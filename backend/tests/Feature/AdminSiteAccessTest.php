<?php

namespace Tests\Feature;

use App\Filament\Pages\ShiftHandover;
use App\Filament\Resources\Devices\Pages\ViewDevice;
use App\Filament\Resources\EnrollmentTokens\Pages\ManageEnrollmentTokens;
use App\Mdm\Models\Clerk;
use App\Mdm\Models\Command;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceGroup;
use App\Mdm\Models\EnrollmentToken;
use App\Mdm\Models\Policy;
use App\Mdm\Role;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** What each admin-site role can open and do: super admin controls, manager reads, coordinator hands over. */
class AdminSiteAccessTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $policy = Policy::create(['name' => 'Shift phones', 'payload' => ['hotspot' => 'blocked']]);
        $group = DeviceGroup::create(['name' => 'Shift phones', 'policy_id' => $policy->id]);
        $this->device = Device::create(['asset_tag' => 'NP-0001', 'group_id' => $group->id, 'state' => Device::STATE_ENROLLED]);
        Clerk::create(['staff_number' => '1001', 'name' => 'Jane Wanjiru']);
    }

    private function user(Role $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_super_admin_opens_every_page(): void
    {
        $this->actingAs($this->user(Role::SuperAdmin));

        foreach (['/admin', '/admin/devices', "/admin/devices/{$this->device->id}", '/admin/shift-handover',
            '/admin/clerks', '/admin/device-groups', '/admin/users', '/admin/audit-logs'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_manager_reads_the_fleet_but_cannot_control_or_manage_accounts(): void
    {
        $this->actingAs($this->user(Role::Manager));

        $this->get('/admin/devices')->assertOk();
        $this->get("/admin/devices/{$this->device->id}")->assertOk();
        $this->get('/admin/audit-logs')->assertOk();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/shift-handover')->assertForbidden();
        $this->get("/admin/devices/{$this->device->id}/edit")->assertForbidden();

        Livewire::test(ViewDevice::class, ['record' => $this->device->getRouteKey()])
            ->assertActionHidden('lock')
            ->assertActionHidden('hotspot');
    }

    public function test_coordinator_only_records_handovers(): void
    {
        $this->actingAs($this->user(Role::Coordinator));

        $this->get('/admin/shift-handover')->assertOk();
        $this->get('/admin/devices')->assertForbidden();
        $this->get('/admin/device-groups')->assertForbidden();
        $this->get('/admin/audit-logs')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
    }

    public function test_super_admin_locks_a_phone_from_its_page(): void
    {
        $admin = $this->user(Role::SuperAdmin);
        $this->actingAs($admin);

        Livewire::test(ViewDevice::class, ['record' => $this->device->getRouteKey()])
            ->callAction('lock')
            ->assertHasNoActionErrors();

        $command = Command::sole();
        $this->assertSame('lock', $command->type);
        $this->assertSame('queued', $command->status);
        $this->assertTrue($command->requestedBy->is($admin));
        $this->assertDatabaseHas('mdm_audit_log', ['action' => 'command.lock', 'actor_id' => $admin->id]);
    }

    public function test_super_admin_overrides_the_hotspot_for_one_phone(): void
    {
        $this->actingAs($this->user(Role::SuperAdmin));

        Livewire::test(ViewDevice::class, ['record' => $this->device->getRouteKey()])
            ->callAction('hotspot', data: ['hotspot' => 'allowed'])
            ->assertHasNoActionErrors();

        $this->assertSame(['hotspot' => 'allowed'], $this->device->fresh()->policy_overrides);
    }

    public function test_super_admin_creates_an_enrollment_code_shown_once(): void
    {
        $this->actingAs($this->user(Role::SuperAdmin));
        $this->get('/admin/enrollment-tokens')->assertOk();

        Livewire::test(ManageEnrollmentTokens::class)
            ->callAction('create', data: ['label' => 'Pilot', 'group_id' => $this->device->group_id, 'max_uses' => 6, 'valid_days' => 7])
            ->assertHasNoActionErrors()
            ->assertNotified('Enrollment code created');

        $token = EnrollmentToken::sole();
        $this->assertSame(6, $token->max_uses);
        $this->assertSame(64, strlen($token->token_hash));

        $this->actingAs($this->user(Role::Manager));
        $this->get('/admin/enrollment-tokens')->assertForbidden();
    }

    public function test_coordinator_hands_a_phone_to_a_clerk(): void
    {
        $this->actingAs($this->user(Role::Coordinator));
        $clerk = Clerk::sole();

        Livewire::test(ShiftHandover::class)
            ->callAction(TestAction::make('handOver')->table($this->device), data: ['clerk_id' => $clerk->id, 'note' => 'Battery 80%'])
            ->assertHasNoActionErrors();

        $this->assertTrue($this->device->fresh()->currentAssignment->clerk->is($clerk));
    }
}
