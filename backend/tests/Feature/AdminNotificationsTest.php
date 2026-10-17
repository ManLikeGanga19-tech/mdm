<?php

namespace Tests\Feature;

use App\Filament\Pages\ShiftHandover;
use App\Mdm\AlertMetric;
use App\Mdm\Models\AlertRule;
use App\Mdm\Models\Crew;
use App\Mdm\Models\Device;
use App\Mdm\Role;
use App\Mdm\Services\AlertService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The bell: alerts land there, the page's chime script finds the newest one; and who sees "My shift". */
class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_alert_reaches_the_bell_and_the_chime_endpoint(): void
    {
        $admin = User::factory()->create(['role' => Role::SuperAdmin]);
        AlertRule::query()->where('metric', AlertMetric::Offline->value)->update(['enabled' => true]);
        Device::create(['asset_tag' => 'NP-0001', 'state' => Device::STATE_ENROLLED, 'enrolled_at' => now()->subDay(), 'last_checkin_at' => now()->subHours(2)]);
        $this->actingAs($admin);

        $this->getJson('/admin/notifications/latest')->assertOk()->assertJson(['unread' => 0, 'latest' => null]);

        app(AlertService::class)->check();

        $this->getJson('/admin/notifications/latest')->assertOk()->assertJson([
            'unread' => 1,
            'latest' => ['title' => 'Phone offline: NP-0001', 'status' => 'danger'],
        ]);
        $this->get('/admin')->assertOk()->assertSee('mdm-chime-toggle', false);
    }

    public function test_the_chime_endpoint_needs_a_signed_in_account(): void
    {
        $this->getJson('/admin/notifications/latest')->assertUnauthorized();
    }

    public function test_a_crew_coordinator_sees_my_shift(): void
    {
        $crew = Crew::create(['name' => 'Crew 1']);
        $this->actingAs(User::factory()->create(['role' => Role::Coordinator, 'crew_id' => $crew->id]));

        $this->get(ShiftHandover::getUrl())->assertOk()->assertSee('My shift');
    }

    public function test_an_admin_outside_the_crews_sees_shift_handovers_not_my_shift(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]));

        $this->get(ShiftHandover::getUrl())->assertOk()->assertSee('Shift handovers')->assertDontSee('My shift');
    }
}
