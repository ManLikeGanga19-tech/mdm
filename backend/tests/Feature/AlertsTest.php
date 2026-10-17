<?php

namespace Tests\Feature;

use App\Filament\Resources\AlertRules\Pages\ManageAlertRules;
use App\Filament\Resources\Alerts\Pages\ManageAlerts;
use App\Mdm\AlertMetric;
use App\Mdm\Models\Alert;
use App\Mdm\Models\AlertRule;
use App\Mdm\Models\Crew;
use App\Mdm\Models\CrewHandover;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceGroup;
use App\Mdm\Models\Policy;
use App\Mdm\Models\RosterEntry;
use App\Mdm\Models\Shift;
use App\Mdm\Models\UsageSample;
use App\Mdm\Notifications\AlertsTriggered;
use App\Mdm\Role;
use App\Mdm\Services\AlertService;
use App\Mdm\Services\UsageReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Alert rules: what is raised, when it clears, who is told. Shifts A/B/C (06:30/14:30/22:30 EAT) come from the migrations. */
class AlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-20 08:00', 'Africa/Nairobi')->utc()); // Shift A running, its handover closed 07:00
        $this->admin = User::factory()->create(['role' => Role::SuperAdmin, 'email' => 'admin@noahport.test']);
    }

    private function rule(AlertMetric $metric, array $extra = []): AlertRule
    {
        $rule = AlertRule::query()->where('metric', $metric->value)->sole(); // the suggested rules, created switched off
        $rule->update(['enabled' => true] + $extra);

        return $rule;
    }

    private function phone(string $tag, array $extra = []): Device
    {
        return Device::create($extra + ['asset_tag' => $tag, 'state' => Device::STATE_ENROLLED, 'enrolled_at' => now()->subDays(3), 'last_checkin_at' => now()]);
    }

    private function check(): int
    {
        return app(AlertService::class)->check();
    }

    public function test_suggested_rules_start_switched_off_and_raise_nothing(): void
    {
        $this->phone('NP-0001', ['last_checkin_at' => now()->subDay(), 'battery_percent' => 3, 'battery_charging' => false]);

        $this->assertSame(6, AlertRule::count());
        $this->assertSame(0, $this->check());
        Notification::assertNothingSent();
    }

    public function test_an_offline_phone_is_alerted_once_cleared_when_it_checks_in_and_not_repeated_for_6_hours(): void
    {
        $this->rule(AlertMetric::Offline); // 60 minutes
        $phone = $this->phone('NP-0001', ['last_checkin_at' => now()->subMinutes(90)]);
        $fine = $this->phone('NP-0002');

        $this->assertSame(1, $this->check());
        $alert = Alert::sole();
        $this->assertSame($phone->id, $alert->device_id);
        $this->assertStringContainsString('NP-0001 has not checked in for 1 hour', $alert->message);
        $this->assertNotNull($alert->notified_at);
        Notification::assertSentTo($this->admin, AlertsTriggered::class, fn (AlertsTriggered $n) => $n->subject === 'Phone offline: NP-0001');

        $this->assertSame(0, $this->check(), 'still offline: no second alert');

        $phone->update(['last_checkin_at' => now()]);
        $this->check();
        $this->assertNotNull($alert->fresh()->resolved_at, 'cleared once it checks in');

        $this->travel(2)->hours();
        $fine->update(['last_checkin_at' => now()]);
        $this->assertSame(0, $this->check(), 'offline again within 6 h: not repeated');
        $this->travel(5)->hours();
        $fine->update(['last_checkin_at' => now()]);
        $this->assertSame(1, $this->check(), 'offline later: a new alert');
    }

    public function test_a_hotspot_limit_is_alerted_once_per_phone_per_day(): void
    {
        $this->rule(AlertMetric::HotspotData, ['threshold' => 1024, 'window' => 'day']);
        $heavy = $this->phone('NP-0001');
        $light = $this->phone('NP-0002');
        $sample = fn (Device $d, int $mb, string $package = UsageReport::TETHERING) => UsageSample::create([
            'device_id' => $d->id, 'package_name' => $package, 'network_type' => 'mobile', 'rx_bytes' => $mb * 1024 * 1024, 'tx_bytes' => 0,
            'period_start' => now()->subHour(), 'period_end' => now(),
        ]);
        $sample($heavy, 1536);
        $sample($light, 300);
        $sample($light, 2000, 'com.android.chrome'); // the phone's own data, not hotspot
        UsageSample::create(['device_id' => $light->id, 'package_name' => UsageReport::TETHERING, 'network_type' => 'mobile',
            'rx_bytes' => 2000 * 1024 * 1024, 'tx_bytes' => 0, 'period_start' => now()->subDay(), 'period_end' => now()->subDay()->addHour()]); // yesterday

        $this->assertSame(1, $this->check());
        $this->assertStringContainsString('NP-0001 has shared through its hotspot 1.50 GB today', Alert::sole()->message);
        $this->assertSame(0, $this->check(), 'once per day');

        $this->travel(1)->days();
        $sample($heavy, 1100);
        $this->assertSame(1, $this->check(), 'a new day');
    }

    public function test_low_battery_is_alerted_during_a_shift_and_clears_when_charging(): void
    {
        $this->rule(AlertMetric::BatteryLow); // 15 %
        $phone = $this->phone('NP-0001', ['battery_percent' => 9, 'battery_charging' => false]);
        $this->phone('NP-0002', ['battery_percent' => 9, 'battery_charging' => true]);
        $this->phone('NP-0003', ['battery_percent' => 9, 'battery_charging' => false, 'last_checkin_at' => now()->subHours(3)]); // stale reading

        $this->assertSame(1, $this->check());
        $this->assertSame($phone->id, Alert::sole()->device_id);

        $phone->update(['battery_charging' => true]);
        $this->check();
        $this->assertNotNull(Alert::sole()->resolved_at);
    }

    public function test_battery_is_not_alerted_between_shifts(): void
    {
        Shift::query()->where('name', 'Shift C')->update(['active' => false]); // no night shift
        $this->travelTo(Carbon::parse('2026-10-20 23:30', 'Africa/Nairobi')->utc());
        $this->rule(AlertMetric::BatteryLow);
        $this->phone('NP-0001', ['battery_percent' => 5, 'battery_charging' => false]);

        $this->assertSame(0, $this->check());
    }

    public function test_a_phone_stuck_on_the_handover_lock_is_alerted_after_the_threshold(): void
    {
        $this->rule(AlertMetric::HandoverLock, ['threshold' => 30]);
        $policy = Policy::create(['name' => 'Shift phones', 'payload' => ['handover_lock' => true]]);
        $group = DeviceGroup::create(['name' => 'Shift phones', 'policy_id' => $policy->id]);
        $this->phone('NP-0001', ['group_id' => $group->id]);

        $this->travelTo(Carbon::parse('2026-10-20 07:20', 'Africa/Nairobi')->utc());
        $this->assertSame(0, $this->check(), 'locked 20 minutes: under the threshold');
        $this->travelTo(Carbon::parse('2026-10-20 07:40', 'Africa/Nairobi')->utc());
        $this->assertSame(1, $this->check());
        $this->assertStringContainsString('because nobody recorded its handover for Shift A', Alert::sole()->message);
    }

    public function test_a_missed_then_unaccepted_crew_handover_is_alerted_only_where_the_roster_has_a_crew(): void
    {
        $this->rule(AlertMetric::HandoverMissed, ['threshold' => 15]);
        $shiftA = Shift::query()->where('name', 'Shift A')->sole();

        $this->assertSame(0, $this->check(), 'no roster yet: crews not set up, nothing to miss');

        $crew = Crew::create(['name' => 'Crew 1']);
        RosterEntry::create(['date' => '2026-10-20', 'shift_id' => $shiftA->id, 'crew_id' => $crew->id]);
        $this->assertSame(1, $this->check());
        $this->assertSame('No crew handover was recorded for Shift A on Tue 20 Oct.', Alert::sole()->message);
        $this->assertNull(Alert::sole()->device_id);
        $this->assertSame(0, $this->check());

        CrewHandover::create(['shift_id' => $shiftA->id, 'date' => '2026-10-20', 'to_crew_id' => $crew->id, 'handed_at' => now()]);
        $this->assertSame(1, $this->check(), 'handed over but still not accepted');
        $this->assertStringContainsString('not accepted by Crew 1', Alert::query()->latest('id')->first()->message);
    }

    public function test_recipients_are_the_rule_roles_plus_extra_addresses_without_duplicates(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        User::factory()->create(['role' => Role::Manager, 'disabled_at' => now()]);
        $this->rule(AlertMetric::Offline, [
            'recipient_role_ids' => [Role::Manager->model()->id],
            'recipient_emails' => ['ops@noahport.test', 'ADMIN@noahport.test', $manager->email],
        ]);
        $this->phone('NP-0001', ['last_checkin_at' => now()->subHours(2)]);

        $this->check();

        Notification::assertSentTo($manager, AlertsTriggered::class);
        Notification::assertNotSentTo($this->admin, AlertsTriggered::class); // not a manager: only by address
        $routes = [];
        Notification::assertSentOnDemand(AlertsTriggered::class, function ($n, $channels, AnonymousNotifiable $to) use (&$routes) {
            $routes[] = $to->routes['mail'];

            return true;
        });
        $this->assertEqualsCanonicalizing(['ops@noahport.test', 'admin@noahport.test'], $routes);
        $this->assertSame(['database', 'mail'], (new AlertsTriggered(AlertRule::find(1), collect()))->via($manager));
    }

    public function test_without_a_mail_server_alerts_only_go_to_the_admin_site_bell(): void
    {
        config(['mail.default' => 'log']);
        $notification = new AlertsTriggered(AlertRule::first(), collect());

        $this->assertSame(['database'], $notification->via($this->admin));
        $this->assertSame([], $notification->via(new AnonymousNotifiable));
    }

    public function test_the_email_lists_every_new_alert_and_links_to_the_alerts_page(): void
    {
        $rule = $this->rule(AlertMetric::Offline);
        foreach (['NP-0001', 'NP-0002', 'NP-0003', 'NP-0004'] as $tag) {
            $this->phone($tag, ['last_checkin_at' => now()->subHours(2)]);
        }
        $this->check();

        Notification::assertSentTo($this->admin, AlertsTriggered::class, function (AlertsTriggered $n) {
            $html = (string) $n->toMail($this->admin)->render();

            return $n->subject === 'Phone offline: NP-0001, NP-0002, NP-0003 and 1 more'
                && substr_count($html, 'has not checked in for 2 hours') === 4
                && str_contains($html, '/admin/alerts');
        });
        Notification::assertSentToTimes($this->admin, AlertsTriggered::class, 1);
    }

    public function test_managers_see_and_acknowledge_alerts_but_only_super_admins_change_rules(): void
    {
        $this->rule(AlertMetric::Offline);
        $this->phone('NP-0001', ['last_checkin_at' => now()->subHours(2)]);
        $this->check();
        $manager = User::factory()->create(['role' => Role::Manager]);
        $this->actingAs($manager);

        $this->get('/admin/alerts')->assertOk()->assertSee('NP-0001');
        $this->get('/admin/alert-rules')->assertForbidden();
        Livewire::test(ManageAlerts::class)->callTableAction('acknowledge', Alert::sole());
        $this->assertSame($manager->id, Alert::sole()->acknowledged_by);
        $this->assertSame(0, Alert::query()->open()->count());
    }

    public function test_a_super_admin_edits_a_rule_and_it_is_audited(): void
    {
        $this->actingAs($this->admin);
        $rule = AlertRule::query()->where('metric', AlertMetric::MobileData->value)->sole();

        $this->get('/admin/alert-rules')->assertOk()->assertSee('Mobile data over limit');
        Livewire::test(ManageAlertRules::class)
            ->callTableAction('edit', $rule, data: ['threshold' => 5000, 'window' => 'week', 'enabled' => true, 'recipient_emails' => ['ops@noahport.test']])
            ->assertHasNoTableActionErrors();

        $rule->refresh();
        $this->assertSame('5000 MB per week', $rule->describeThreshold());
        $this->assertTrue($rule->enabled);
        $this->assertDatabaseHas('mdm_audit_log', ['action' => 'alert_rule.updated']);
    }
}
