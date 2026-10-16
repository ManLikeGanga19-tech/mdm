<?php

namespace Tests\Feature;

use App\Filament\Resources\Devices\Pages\ViewDevice;
use App\Filament\Resources\Devices\Widgets\DeviceLiveView;
use App\Mdm\Models\Command;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceCredential;
use App\Mdm\Role;
use App\Mdm\Services\EnrollmentService;
use App\Mdm\Services\LiveView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class LiveViewTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = Device::create(['asset_tag' => 'NP-0001', 'state' => Device::STATE_ENROLLED]);
        $this->token = Str::random(48);
        DeviceCredential::create(['device_id' => $this->device->id, 'token_hash' => EnrollmentService::hash($this->token), 'created_at' => now()]);
    }

    private function sample(int $rx): array
    {
        return ['at' => now()->toIso8601ZuluString(), 'rx_bps' => $rx, 'tx_bps' => 250_000, 'network' => 'mobile', 'signal_dbm' => -87, 'down_kbps' => 12000, 'up_kbps' => 3000];
    }

    public function test_starting_sends_a_command_and_the_phone_streams_until_stopped(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]));
        Livewire::test(ViewDevice::class, ['record' => $this->device->getRouteKey()])->callAction('liveView')->assertHasNoActionErrors();

        $command = Command::sole();
        $this->assertSame('live_view', $command->type);
        $this->assertSame(['seconds' => 600], $command->payload);
        $this->assertTrue(app(LiveView::class)->isActive($this->device));

        $this->withToken($this->token)->postJson('/api/device/v1/live', ['samples' => [$this->sample(4_500_000)]])
            ->assertOk()->assertJson(['continue' => true]);

        Livewire::test(DeviceLiveView::class, ['record' => $this->device])->assertSee('4.5 Mbps')->assertSee('Mobile data')->assertSee('-87 dBm');

        Livewire::test(ViewDevice::class, ['record' => $this->device->getRouteKey()])->callAction('stopLiveView');
        $this->withToken($this->token)->postJson('/api/device/v1/live', ['samples' => [$this->sample(100)]])
            ->assertOk()->assertJson(['continue' => false]);
        $this->assertDatabaseHas('mdm_audit_log', ['action' => 'live_view.stopped']);
    }

    public function test_the_session_ends_on_its_own(): void
    {
        app(LiveView::class)->start($this->device, 60);
        $this->travel(61)->seconds();

        $this->withToken($this->token)->postJson('/api/device/v1/live', ['samples' => [$this->sample(1)]])->assertJson(['continue' => false]);
    }

    public function test_only_super_admins_can_start_it_and_bad_samples_are_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));
        Livewire::test(ViewDevice::class, ['record' => $this->device->getRouteKey()])->assertActionHidden('liveView');

        $this->withToken($this->token)->postJson('/api/device/v1/live', ['samples' => [['at' => 'x', 'rx_bps' => -1, 'tx_bps' => 0]]])
            ->assertUnprocessable();
    }
}
