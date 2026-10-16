<?php

namespace Tests\Feature;

use App\Filament\Resources\Devices\Pages\ViewDevice;
use App\Filament\Resources\Devices\Widgets\DeviceLiveConsole;
use App\Filament\Resources\Devices\Widgets\DeviceLiveView;
use App\Mdm\Models\Command;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceCredential;
use App\Mdm\Role;
use App\Mdm\Services\CommandService;
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

    public function test_the_console_shows_app_speeds_phone_events_and_admin_actions(): void
    {
        $admin = User::factory()->create(['role' => Role::SuperAdmin, 'name' => 'Daniel']);
        $this->actingAs($admin);
        app(LiveView::class)->start($this->device);
        $this->travel(1)->seconds();

        $sample = $this->sample(4_200_000) + [
            'apps' => [
                ['package' => 'com.android.chrome', 'label' => 'Chrome', 'rx_bps' => 3_100_000, 'tx_bps' => 200_000],
                ['package' => 'com.whatsapp', 'label' => 'WhatsApp', 'rx_bps' => 600_000, 'tx_bps' => 0],
            ],
            'apps_age_s' => 12,
        ];
        $this->withToken($this->token)->postJson('/api/device/v1/live', [
            'samples' => [$sample],
            'events' => [['at' => now()->toIso8601ZuluString(), 'text' => 'Network changed: Wi-Fi -> Mobile data']],
        ])->assertOk();
        $this->travel(1)->seconds();
        app(CommandService::class)->send($this->device, 'lock');

        Livewire::test(DeviceLiveConsole::class, ['record' => $this->device])
            ->assertSee('Chrome ↓3.1 Mbps ↑200 kbps')
            ->assertSee('WhatsApp ↓600 kbps')
            ->assertSee('(apps 12s ago)')
            ->assertSee('Network changed: Wi-Fi -&gt; Mobile data', false)
            ->assertSee('Command sent: Lock now (by Daniel)')
            ->assertSeeInOrder(['Live view started', 'Chrome', 'Network changed', 'Command sent: Lock now']);
    }

    public function test_events_alone_are_accepted_and_nothing_is_kept_without_a_session(): void
    {
        $this->withToken($this->token)->postJson('/api/device/v1/live', [
            'samples' => [], 'events' => [['at' => now()->toIso8601ZuluString(), 'text' => 'Charger plugged in']],
        ])->assertOk()->assertJson(['continue' => false]);

        app(CommandService::class)->send($this->device, 'lock');
        $this->assertCount(1, app(LiveView::class)->lines($this->device)); // the phone's line; the server adds none
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
