<?php

namespace Tests\Feature;

use App\Filament\Resources\Devices\Widgets\DeviceLiveStatus;
use App\Filament\Widgets\FleetOverview;
use App\Filament\Widgets\HandoversChart;
use App\Filament\Widgets\HoldersNow;
use App\Filament\Widgets\NeedsAttention;
use App\Filament\Widgets\RecentActivity;
use App\Mdm\Models\Clerk;
use App\Mdm\Models\Device;
use App\Mdm\Role;
use App\Mdm\Services\FleetHealth;
use App\Mdm\Services\HandoverService;
use App\Mdm\Services\PolicyBuilder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_fleet_health_flags_each_problem_in_plain_words(): void
    {
        $health = app(FleetHealth::class);

        $this->assertSame(['Not enrolled yet'], $health->issues(Device::create(['asset_tag' => 'NP-0001'])));

        $silent = Device::create(['asset_tag' => 'NP-0002', 'state' => Device::STATE_ENROLLED,
            'last_checkin_at' => now()->subHours(3), 'security_patch' => '2026-01-05']);
        $issues = $health->issues($silent);
        $this->assertContains('Silent for 3 hours', $issues);
        $this->assertContains('Rules not applied yet', $issues);
        $this->assertContains('Security patch 2026-01-05', $issues);
        $this->assertContains('Staff notice not acknowledged', $issues);

        $fine = Device::create(['asset_tag' => 'NP-0003', 'state' => Device::STATE_ENROLLED,
            'last_checkin_at' => now()->subMinutes(5), 'security_patch' => now()->subDays(20)->toDateString(),
            'notice_acknowledged_at' => now()]);
        $fine->update(['applied_policy_version' => app(PolicyBuilder::class)->build($fine)['version']]);
        $this->assertSame([], $health->issues($fine->fresh()));
    }

    public function test_dashboard_shows_kpis_and_no_account_card(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]));
        $phone = Device::create(['asset_tag' => 'NP-0001', 'state' => Device::STATE_ENROLLED, 'last_checkin_at' => now()]);
        app(HandoverService::class)->handOver($phone, Clerk::create(['staff_number' => '1001', 'name' => 'Jane Wanjiru']));

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Fleet overview')
            ->assertDontSee('fi-account-widget');

        Livewire::test(FleetOverview::class)->assertSee('Phones enrolled')->assertSee('Held by a clerk')->assertSee('1 of 1');
        Livewire::test(NeedsAttention::class)->assertSee('NP-0001')->assertSee('Staff notice not acknowledged');
        Livewire::test(HoldersNow::class)->assertSee('Jane Wanjiru');
        Livewire::test(RecentActivity::class)->assertSee('Handover');
        Livewire::test(HandoversChart::class)->assertOk();
    }

    public function test_phone_page_live_status_shows_charging_and_network(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]));
        $phone = Device::create(['asset_tag' => 'NP-0001', 'state' => Device::STATE_ENROLLED, 'last_checkin_at' => now(),
            'battery_percent' => 57, 'battery_charging' => true, 'network_type' => 'wifi']);

        Livewire::test(DeviceLiveStatus::class, ['record' => $phone])
            ->assertSee('57%')->assertSee('Charging')->assertSee('Wi-Fi')->assertSee('Reachable');

        $phone->update(['battery_charging' => false, 'network_type' => 'mobile']);
        Livewire::test(DeviceLiveStatus::class, ['record' => $phone])
            ->assertSee('On battery')->assertSee('Mobile data');
    }

    public function test_coordinator_dashboard_only_shows_who_holds_which_phone(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Coordinator]));

        $this->assertFalse(FleetOverview::canView());
        $this->assertFalse(NeedsAttention::canView());
        $this->assertFalse(RecentActivity::canView());
        $this->assertTrue(HoldersNow::canView());
        $this->get('/admin')->assertOk();
    }
}
