<?php

namespace Tests\Feature;

use App\Filament\Resources\Shifts\Pages\ManageShifts;
use App\Filament\Widgets\HandoverMonitor;
use App\Mdm\Models\Clerk;
use App\Mdm\Models\Device;
use App\Mdm\Models\Setting;
use App\Mdm\Models\Shift;
use App\Mdm\Models\UsageSample;
use App\Mdm\Models\WorkRule;
use App\Mdm\Role;
use App\Mdm\Services\HandoverService;
use App\Mdm\Services\ShiftReport;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Noahport shifts: A 06:30, B 14:30, C 22:30 (EAT, UTC+3), each opening with a 30-minute handover window. */
class ShiftReportTest extends TestCase
{
    use RefreshDatabase;

    private Shift $a;

    private Shift $c;

    private Device $phone;

    protected function setUp(): void
    {
        parent::setUp();
        // Shifts come from the migration's defaults.
        $this->a = Shift::where('name', 'Shift A')->sole();
        $this->c = Shift::where('name', 'Shift C')->sole();
        $this->phone = Device::create(['asset_tag' => 'NP-0001', 'state' => Device::STATE_ENROLLED]);
    }

    private function eat(string $time): Carbon
    {
        return Carbon::parse($time, 'Africa/Nairobi')->utc();
    }

    public function test_the_running_shift_is_found_including_night_shift_after_midnight(): void
    {
        $this->assertSame([$this->a->id, '2026-10-16'], [Shift::at($this->eat('2026-10-16 06:45'))[0]->id, Shift::at($this->eat('2026-10-16 06:45'))[1]]);
        $this->assertSame('Shift B', Shift::at($this->eat('2026-10-16 14:30'))[0]->name);
        $this->assertSame([$this->c->id, '2026-10-16'], [Shift::at($this->eat('2026-10-16 23:30'))[0]->id, Shift::at($this->eat('2026-10-16 23:30'))[1]]);
        $this->assertSame([$this->c->id, '2026-10-15'], [Shift::at($this->eat('2026-10-16 03:00'))[0]->id, Shift::at($this->eat('2026-10-16 03:00'))[1]]);
    }

    public function test_handover_status_on_time_early_late_missing_and_waiting(): void
    {
        $report = app(ShiftReport::class);
        $handover = app(HandoverService::class);
        $clerk = Clerk::create(['staff_number' => '1001', 'name' => 'Jane Wanjiru']);
        $this->travelTo($this->eat('2026-10-16 12:00'));

        $this->assertSame('Missing', $report->handoverStatus($this->phone, $this->a, '2026-10-16')['status']);

        $handover->handOver($this->phone, $clerk, at: $this->eat('2026-10-16 06:40'));
        $status = $report->handoverStatus($this->phone, $this->a, '2026-10-16');
        $this->assertSame('On time', $status['status']);
        $this->assertSame('Jane Wanjiru', $status['to']);

        $late = Device::create(['asset_tag' => 'NP-0002', 'state' => Device::STATE_ENROLLED]);
        $handover->handOver($late, Clerk::create(['staff_number' => '1002', 'name' => 'B']), at: $this->eat('2026-10-16 07:20'));
        $this->assertSame('Late', $report->handoverStatus($late, $this->a, '2026-10-16')['status']);

        $early = Device::create(['asset_tag' => 'NP-0003', 'state' => Device::STATE_ENROLLED]);
        $handover->handOver($early, Clerk::create(['staff_number' => '1003', 'name' => 'C']), at: $this->eat('2026-10-16 06:15'));
        $this->assertSame('Early', $report->handoverStatus($early, $this->a, '2026-10-16')['status']);

        $this->travelTo($this->eat('2026-10-16 22:40'));
        $this->assertSame('Waiting', $report->handoverStatus($late, $this->c, '2026-10-16')['status']);
    }

    public function test_each_hour_goes_to_the_clerk_holding_the_phone_and_is_split_peak_off_peak(): void
    {
        Setting::put('peak_hours', [['07:00', '10:00']]);
        WorkRule::create(['kind' => 'app', 'pattern' => 'com.safaricom.mpesa.lifestyle', 'label' => 'M-PESA']);
        $day = Clerk::create(['staff_number' => '1001', 'name' => 'Day Clerk']);
        $relief = Clerk::create(['staff_number' => '1002', 'name' => 'Relief Clerk']);
        app(HandoverService::class)->handOver($this->phone, $day, at: $this->eat('2026-10-16 06:35'));
        app(HandoverService::class)->handOver($this->phone, $relief, at: $this->eat('2026-10-16 11:00'));
        $add = function (string $package, int $bytes, string $hourEat) {
            $start = $this->eat($hourEat);
            UsageSample::create(['device_id' => $this->phone->id, 'package_name' => $package, 'network_type' => 'mobile',
                'rx_bytes' => $bytes, 'tx_bytes' => 0, 'period_start' => $start, 'period_end' => $start->copy()->addHour()]);
        };
        $add('com.safaricom.mpesa.lifestyle', 1000, '2026-10-16 08:00'); // peak, Day Clerk, work
        $add('com.google.android.youtube', 3000, '2026-10-16 12:00');   // off-peak, Relief Clerk
        $add('__tethering__', 500, '2026-10-16 12:00');                  // hotspot, Relief Clerk
        $add('com.google.android.youtube', 9999, '2026-10-16 16:00');   // Shift B: not in this report
        $this->travelTo($this->eat('2026-10-16 20:00'));

        $report = app(ShiftReport::class)->build($this->a, '2026-10-16');
        $rows = $report['rows']->keyBy('clerk');

        $this->assertSame(['total' => 1000, 'work' => 1000, 'peak' => 1000, 'hotspot' => 0],
            array_intersect_key($rows['Day Clerk'], array_flip(['total', 'work', 'peak', 'hotspot'])));
        $this->assertSame(['total' => 3500, 'work' => 0, 'peak' => 0, 'off_peak' => 3500, 'hotspot' => 500],
            array_intersect_key($rows['Relief Clerk'], array_flip(['total', 'work', 'peak', 'off_peak', 'hotspot'])));
        $this->assertSame('YouTube', $rows['Relief Clerk']['top_app'] === 'com.google.android.youtube' ? 'YouTube' : $rows['Relief Clerk']['top_app']);
        $this->assertSame(['total' => 4500, 'work' => 1000, 'peak' => 1000, 'hotspot' => 500], $report['totals']);
    }

    public function test_pages_load_for_the_right_roles(): void
    {
        $this->travelTo($this->eat('2026-10-16 06:45'));
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));
        $this->get('/admin/shift-report')->assertOk()->assertSee('Shift A');
        $this->get("/admin/shift-report?date=2026-10-15&shift={$this->c->id}")->assertOk()->assertSee('Shift C');
        $this->get('/admin/shifts')->assertOk();
        $this->get('/admin')->assertOk();
        Livewire::test(HandoverMonitor::class)->assertSee('NP-0001')->assertSee('Waiting');

        $this->actingAs(User::factory()->create(['role' => Role::Coordinator]));
        $this->get('/admin/shift-report')->assertForbidden();
        Livewire::test(HandoverMonitor::class)->assertSee('NP-0001');
    }

    public function test_editing_a_shift_keeps_wall_clock_times(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]));

        Livewire::test(ManageShifts::class)
            ->callAction(TestAction::make('edit')->table($this->a), data: ['starts_at' => '06:00', 'ends_at' => '14:00'])
            ->assertHasNoActionErrors();

        $this->assertSame(['06:00', '14:00'], [substr($this->a->fresh()->starts_at, 0, 5), substr($this->a->fresh()->ends_at, 0, 5)]);
    }

    public function test_super_admin_sets_peak_hours(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]));

        Livewire::test(ManageShifts::class)
            ->callAction('peakHours', data: ['ranges' => [['from' => '17:00', 'to' => '20:00'], ['from' => '06:30', 'to' => '09:00']]])
            ->assertHasNoActionErrors();

        $this->assertSame([['06:30', '09:00'], ['17:00', '20:00']], Setting::get('peak_hours'));
    }
}
