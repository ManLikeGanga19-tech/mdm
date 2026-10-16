<?php

namespace Tests\Feature;

use App\Filament\Resources\Devices\Pages\ViewDevice;
use App\Filament\Resources\Devices\RelationManagers\AppsRelationManager;
use App\Filament\Resources\Devices\RelationManagers\NetworkLogsRelationManager;
use App\Filament\Resources\Devices\Widgets\DeviceBatteryChart;
use App\Filament\Resources\Devices\Widgets\DeviceTopApps;
use App\Filament\Resources\Devices\Widgets\DeviceTopSites;
use App\Filament\Resources\Devices\Widgets\DeviceUsageChart;
use App\Mdm\Models\AppInventory;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceGroup;
use App\Mdm\Models\NetworkLog;
use App\Mdm\Models\Policy;
use App\Mdm\Models\UsageSample;
use App\Mdm\Models\WorkRule;
use App\Mdm\Role;
use App\Mdm\Services\PolicyBuilder;
use App\Mdm\Services\UsageReport;
use App\Mdm\Services\WorkClassifier;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class UsageReportTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $policy = Policy::create(['name' => 'Shift phones', 'payload' => ['hotspot' => 'blocked', 'blocked_apps' => ['com.zhiliaoapp.musically']]]);
        $this->device = Device::create(['asset_tag' => 'NP-0001', 'state' => Device::STATE_ENROLLED,
            'group_id' => DeviceGroup::create(['name' => 'Shift phones', 'policy_id' => $policy->id])->id]);
    }

    private function sample(string $package, int $bytes, string $hour, string $network = 'mobile'): void
    {
        $start = Carbon::parse($hour, 'UTC');
        UsageSample::create(['device_id' => $this->device->id, 'package_name' => $package, 'network_type' => $network,
            'rx_bytes' => $bytes, 'tx_bytes' => 0, 'period_start' => $start, 'period_end' => $start->copy()->addHour()]);
    }

    private function lookup(string $package, string $host, string $at): void
    {
        NetworkLog::create(['device_id' => $this->device->id, 'log_type' => 'dns', 'package_name' => $package, 'host' => $host, 'occurred_at' => Carbon::parse($at, 'UTC')]);
    }

    public function test_work_rules_normalise_addresses_and_match_subdomains(): void
    {
        WorkRule::create(['kind' => 'domain', 'pattern' => 'https://www.Noahport.co.ke/login', 'label' => 'Company PWA']);
        WorkRule::create(['kind' => 'app', 'pattern' => 'com.safaricom.mpesa.lifestyle', 'label' => 'M-PESA']);
        $work = app(WorkClassifier::class);

        $this->assertSame('noahport.co.ke', WorkRule::where('kind', 'domain')->value('pattern'));
        $this->assertTrue($work->isWorkHost('app.noahport.co.ke'));
        $this->assertTrue($work->isWorkHost('noahport.co.ke'));
        $this->assertFalse($work->isWorkHost('notnoahport.co.ke'));
        $this->assertTrue($work->isWorkApp('com.safaricom.mpesa.lifestyle'));
        $this->assertSame('Company PWA', $work->labelFor('com.android.chrome', 'api.noahport.co.ke'));
    }

    public function test_browser_data_is_shared_across_the_sites_of_each_hour(): void
    {
        WorkRule::create(['kind' => 'domain', 'pattern' => 'noahport.co.ke', 'label' => 'Company PWA']);
        $this->sample('com.android.chrome', 1000, '2026-10-16 05:00');
        $this->lookup('com.android.chrome', 'app.noahport.co.ke', '2026-10-16 05:10');
        $this->lookup('com.android.chrome', 'app.noahport.co.ke', '2026-10-16 05:20');
        $this->lookup('com.android.chrome', 'www.youtube.com', '2026-10-16 05:30');
        $this->sample('com.whatsapp', 400, '2026-10-16 05:00', 'wifi');

        $report = app(UsageReport::class);
        [$from, $to] = [Carbon::parse('2026-10-16 00:00', 'UTC'), Carbon::parse('2026-10-17 00:00', 'UTC')];

        $sites = $report->sites($this->device, $from, $to)->keyBy('host');
        $this->assertSame(666, $sites['app.noahport.co.ke']['bytes']);
        $this->assertTrue($sites['app.noahport.co.ke']['work']);
        $this->assertSame(333, $sites['www.youtube.com']['bytes']);
        $this->assertFalse($sites['www.youtube.com']['work']);

        $apps = $report->apps($this->device, $from, $to)->keyBy('package');
        $this->assertSame(666, $apps['com.android.chrome']['work']);
        $this->assertTrue($apps['com.android.chrome']['estimated']);
        $this->assertSame(0, $apps['com.whatsapp']['work']);
        $this->assertSame(400, $apps['com.whatsapp']['wifi']);
        $this->assertSame(['total' => 1400, 'work' => 666], $report->totals($this->device, $from, $to));
    }

    public function test_hourly_totals_separate_hotspot_from_mobile(): void
    {
        $this->sample('com.android.chrome', 1000, '2026-10-16 05:00');
        $this->sample('__tethering__', 5000, '2026-10-16 05:00');
        $this->sample('com.whatsapp', 300, '2026-10-16 05:00', 'wifi');

        $hour = app(UsageReport::class)->hourly($this->device, Carbon::parse('2026-10-16 00:00', 'UTC'), Carbon::parse('2026-10-17 00:00', 'UTC'))->sole();

        $this->assertSame(['mobile' => 1000, 'wifi' => 300, 'hotspot' => 5000], array_intersect_key($hour, array_flip(['mobile', 'wifi', 'hotspot'])));
    }

    public function test_blocking_and_allowing_one_app_on_one_phone(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]));
        $chrome = AppInventory::create(['device_id' => $this->device->id, 'package_name' => 'com.android.chrome', 'label' => 'Chrome', 'last_seen_at' => now()]);
        $tiktok = AppInventory::create(['device_id' => $this->device->id, 'package_name' => 'com.zhiliaoapp.musically', 'label' => 'TikTok', 'last_seen_at' => now()]);
        $rm = fn () => Livewire::test(AppsRelationManager::class, ['ownerRecord' => $this->device->fresh(), 'pageClass' => ViewDevice::class]);
        $blocked = fn () => app(PolicyBuilder::class)->build($this->device->fresh())['blocked_apps'];

        $rm()->callAction(TestAction::make('block')->table($chrome))->assertHasNoActionErrors();
        $this->assertEqualsCanonicalizing(['com.zhiliaoapp.musically', 'com.android.chrome'], $blocked());

        $rm()->callAction(TestAction::make('allow')->table($tiktok))->assertHasNoActionErrors();
        $this->assertSame(['com.android.chrome'], $blocked());

        // The group's list still applies to apps the phone hasn't overridden.
        $this->device->group->policy->update(['payload' => ['hotspot' => 'blocked', 'blocked_apps' => ['com.zhiliaoapp.musically', 'com.facebook.katana']]]);
        $this->assertEqualsCanonicalizing(['com.android.chrome', 'com.facebook.katana'], $blocked());
        $this->assertDatabaseHas('mdm_audit_log', ['action' => 'app.blocked']);
    }

    public function test_phone_page_and_work_rules_page_load_with_the_new_sections(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]));
        $this->sample('com.android.chrome', 2_000_000, now()->subHour()->startOfHour()->toDateTimeString());
        $this->lookup('com.android.chrome', 'www.bbc.com', now()->subMinutes(50)->toDateTimeString());

        $this->get("/admin/devices/{$this->device->id}")->assertOk();
        $this->get('/admin/work-rules')->assertOk();

        Livewire::test(DeviceTopSites::class, ['record' => $this->device])->assertSee('www.bbc.com')->assertSee('Not work');
        Livewire::test(DeviceTopApps::class, ['record' => $this->device])->assertSee('com.android.chrome');
        Livewire::test(DeviceUsageChart::class, ['record' => $this->device])->assertOk();
        Livewire::test(DeviceBatteryChart::class, ['record' => $this->device])->assertOk();
    }

    public function test_phone_housekeeping_is_hidden_until_asked_for(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]));
        WorkRule::create(['kind' => 'domain', 'pattern' => 'noahport.co.ke', 'label' => 'Company PWA']);
        $hour = now()->subHour()->startOfHour();
        $this->sample('com.android.vending', 90_000_000, $hour->toDateTimeString());
        $this->sample('com.android.chrome', 2_000_000, $hour->toDateTimeString());
        $this->lookup('com.android.vending', 'play.googleapis.com', $hour->copy()->addMinutes(5)->toDateTimeString());
        $this->lookup('com.android.chrome', 'beacons2.gvt2.com', $hour->copy()->addMinutes(6)->toDateTimeString());
        $this->lookup('com.android.chrome', 'www.baze.co.ke', $hour->copy()->addMinutes(7)->toDateTimeString());
        $this->lookup('com.android.chrome', 'app.noahport.co.ke', $hour->copy()->addMinutes(8)->toDateTimeString());
        $this->lookup('com.google.uid.shared:10144', 'www.example.com', $hour->copy()->addMinutes(9)->toDateTimeString());

        $sites = app(UsageReport::class)->sites($this->device, now()->subDay(), now())->keyBy('host');
        $this->assertTrue($sites['play.googleapis.com']['background']);
        $this->assertTrue($sites['beacons2.gvt2.com']['background']);
        $this->assertTrue($sites['www.example.com']['background']);
        $this->assertSame('Google Play services', $sites['www.example.com']['label']);
        $this->assertFalse($sites['www.baze.co.ke']['background']);
        $this->assertFalse($sites['app.noahport.co.ke']['background']);

        Livewire::test(DeviceTopSites::class, ['record' => $this->device])
            ->assertSee('www.baze.co.ke')->assertDontSee('play.googleapis.com')
            ->call('$set', 'showBackground', true)
            ->assertSee('play.googleapis.com');

        Livewire::test(NetworkLogsRelationManager::class, ['ownerRecord' => $this->device, 'pageClass' => ViewDevice::class])
            ->assertSee('www.baze.co.ke')->assertSee('app.noahport.co.ke')
            ->assertDontSee('beacons2.gvt2.com')->assertDontSee('www.example.com')
            ->assertSee('Last website records from this phone: never')
            ->filterTable('today')->assertSee('www.baze.co.ke')
            ->call('$set', 'showBackground', true)->assertSee('beacons2.gvt2.com');

        Livewire::test(AppsRelationManager::class, ['ownerRecord' => $this->device, 'pageClass' => ViewDevice::class])
            ->filterTable('system', false)->assertOk();
    }
}
