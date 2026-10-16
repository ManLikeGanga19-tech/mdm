<?php

namespace Tests\Feature;

use App\Filament\Pages\ShiftHandover;
use App\Filament\Resources\Devices\Pages\ViewDevice;
use App\Filament\Resources\Roles\AdminRoleResource;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Mdm\Models\AdminRole;
use App\Mdm\Models\Device;
use App\Mdm\Permission;
use App\Mdm\Role;
use App\Mdm\Services\AccountService;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class RolesAndAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role|AdminRole $role, array $extra = []): User
    {
        return User::factory()->create(['role' => $role] + $extra);
    }

    public function test_built_in_roles_keep_the_access_they_had(): void
    {
        $this->assertTrue(Role::SuperAdmin->model()->allows(Permission::RolesManage));
        $this->assertEqualsCanonicalizing(
            ['fleet.view', 'reports.view', 'websites.view', 'audit.view'],
            Role::Manager->model()->permissionValues(),
        );
        $this->assertSame(['handover.record'], Role::Coordinator->model()->permissionValues());
    }

    public function test_a_custom_role_controls_phones_without_erase_or_websites(): void
    {
        $it = AdminRole::create(['name' => 'IT support']);
        $it->syncPermissions([Permission::FleetView->value, Permission::PhonesControl->value, Permission::LiveView->value]);
        $device = Device::create(['asset_tag' => 'NP-0001', 'state' => Device::STATE_ENROLLED]);
        $this->actingAs($this->user($it));

        $this->get("/admin/devices/{$device->id}")->assertOk();
        Livewire::test(ViewDevice::class, ['record' => $device->getRouteKey()])
            ->assertActionVisible('lock')
            ->assertActionVisible('liveView')
            ->assertActionHidden('wipe')
            ->assertActionHidden('hotspot');
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/roles')->assertForbidden();
    }

    public function test_a_super_admin_edits_a_role_on_the_grid_and_it_is_audited(): void
    {
        $this->actingAs($this->user(Role::SuperAdmin));
        $manager = Role::Manager->model();

        Livewire::test(ManageRoles::class)
            ->callTableAction('edit', $manager, data: array_merge(AdminRoleResource::fillPermissions($manager), ['permissions_phones' => ['fleet.view', 'phones.live']]))
            ->assertHasNoTableActionErrors();

        $this->assertTrue($manager->fresh()->allows(Permission::LiveView));
        $this->assertDatabaseHas('mdm_audit_log', ['action' => 'role.updated']);
        $this->assertFalse(AdminRoleResource::canEdit(Role::SuperAdmin->model()), 'super admin is fixed');
        $this->assertFalse(AdminRoleResource::canDelete($manager), 'built-in roles stay');
    }

    public function test_a_blocked_page_shows_the_not_allowed_page_with_the_role(): void
    {
        $this->actingAs($this->user(Role::Manager, ['name' => 'Mary Manager']));

        $this->get('/admin/users')->assertForbidden()
            ->assertSee("You don't have access to this")
            ->assertSee('Mary Manager · Manager')
            ->assertSee('Roles &amp; permissions', false);
    }

    public function test_a_disabled_account_is_shut_out(): void
    {
        $user = $this->user(Role::Manager, ['disabled_at' => now()]);

        $this->actingAs($user)->get('/admin')->assertForbidden()->assertSee('This account is disabled');
        $this->assertFalse($user->hasPermission(Permission::FleetView));
    }

    public function test_the_last_super_admin_cannot_be_disabled_demoted_or_deleted(): void
    {
        $only = $this->user(Role::SuperAdmin);
        $accounts = app(AccountService::class);

        foreach ([fn () => $accounts->disable($only), fn () => $accounts->changeRole($only, Role::Manager->model()), fn () => $accounts->delete($only)] as $change) {
            try {
                $change();
                $this->fail('the last super admin was changed');
            } catch (RuntimeException) {
            }
        }
        $this->assertTrue($only->fresh()->isSuperAdmin());

        $this->user(Role::SuperAdmin); // with a second one it's allowed
        $accounts->changeRole($only, Role::Manager->model());
        $this->assertFalse($only->fresh()->isSuperAdmin());
    }

    public function test_an_invite_gives_a_working_set_password_link(): void
    {
        $this->actingAs($this->user(Role::SuperAdmin));

        Livewire::test(ManageUsers::class)
            ->callAction('invite', ['name' => 'Grace Achieng', 'email' => 'Grace@Noahport.test', 'role_id' => Role::Coordinator->model()->id])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $grace = User::where('email', 'grace@noahport.test')->sole();
        $this->assertSame('coordinator', $grace->role->key);
        $this->assertNotNull($grace->invited_at);
        $link = app(AccountService::class)->setPasswordLink($grace);
        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? basename(parse_url($link, PHP_URL_PATH));
        $this->assertTrue(Password::broker()->tokenExists($grace, $token));
        $this->assertDatabaseHas('mdm_audit_log', ['action' => 'account.invited']);
    }

    public function test_each_role_starts_where_its_work_is_and_sign_ins_are_recorded(): void
    {
        $coordinator = $this->user(Role::Coordinator);
        $this->actingAs($coordinator);
        $this->assertSame(ShiftHandover::getUrl(), Filament::getHomeUrl());

        $this->actingAs($this->user(Role::Manager));
        $this->assertNotSame(ShiftHandover::getUrl(), Filament::getHomeUrl());

        event(new Login('web', $coordinator, false));
        $this->assertNotNull($coordinator->fresh()->last_login_at);
    }
}
