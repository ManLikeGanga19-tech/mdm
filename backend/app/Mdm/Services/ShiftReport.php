<?php

namespace App\Mdm\Services;

use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceAssignment;
use App\Mdm\Models\Setting;
use App\Mdm\Models\Shift;
use App\Mdm\Models\StatusReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One shift on one date: per phone and clerk, data used (work / not work, peak / off-peak, hotspot), top app and
 * site, battery, how reliably the phone reported, and whether the opening handover happened in its window.
 *
 * Usage is hourly, so each hour is attributed to the clerk holding the phone at its midpoint (from the handover
 * records) and counts as peak if it starts inside a peak range. Work share uses UsageReport (per-site = estimate).
 */
class ShiftReport
{
    /** A handover up to this long before the window still counts as done (early) for this shift. */
    public const EARLY_GRACE_MINUTES = 30;

    public function __construct(private UsageReport $usage, private HandoverService $handovers) {}

    public function build(Shift $shift, string $date): array
    {
        [$start, $end] = $shift->window($date);
        $devices = Device::query()->where('state', Device::STATE_ENROLLED)->orderBy('asset_tag')->get();

        $rows = $devices->flatMap(fn (Device $d) => $this->rowsFor($d, $start, $end));

        return [
            'start' => $start,
            'end' => $end,
            'handover' => $shift->handoverWindow($date),
            'rows' => $rows->values(),
            'handovers' => $devices->map(fn (Device $d) => $this->handoverStatus($d, $shift, $date))->values(),
            'totals' => [
                'total' => (int) $rows->sum('total'),
                'work' => (int) $rows->sum('work'),
                'peak' => (int) $rows->sum('peak'),
                'hotspot' => (int) $rows->sum('hotspot'),
            ],
        ];
    }

    /** Is the hour starting at $hour (UTC) inside a peak range (admin's time zone)? */
    public function isPeak(Carbon $hour): bool
    {
        $local = $hour->copy()->setTimezone(config('app.display_timezone'))->format('H:i');
        foreach (Setting::get('peak_hours') as [$from, $to]) {
            if ($local >= $from && $local < $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * How the handover that opens this shift went for one phone.
     *
     * @return array{device: Device, status: string, at: ?Carbon, by: ?string, to: ?string}
     */
    public function handoverStatus(Device $device, Shift $shift, string $date): array
    {
        [$windowStart, $windowEnd] = $shift->handoverWindow($date);
        [, $shiftEnd] = $shift->window($date);

        $handover = DeviceAssignment::query()->with(['clerk', 'assignedBy'])
            ->where('device_id', $device->id)
            ->where('started_at', '>=', $windowStart->copy()->subMinutes(self::EARLY_GRACE_MINUTES))
            ->where('started_at', '<', $shiftEnd)
            ->orderBy('started_at')
            ->first();

        $status = match (true) {
            $handover === null => $windowEnd->isFuture() ? 'Waiting' : 'Missing',
            $handover->started_at->lt($windowStart) => 'Early',
            $handover->started_at->lte($windowEnd) => 'On time',
            default => 'Late',
        };

        return [
            'device' => $device,
            'status' => $status,
            'at' => $handover?->started_at,
            'by' => $handover?->assignedBy?->name,
            'to' => $handover?->clerk?->name,
        ];
    }

    /** One row per clerk who held the phone during the shift (or "Nobody" for unheld hours). */
    private function rowsFor(Device $device, Carbon $start, Carbon $end): Collection
    {
        $segments = [];
        for ($hour = $start->copy()->startOfHour(); $hour->lt($end); $hour->addHour()) {
            $apps = $this->usage->apps($device, $hour, $hour->copy()->addHour());
            if ($apps->isEmpty()) {
                continue;
            }
            $clerk = $this->handovers->holderAt($device, $hour->copy()->addMinutes(30));
            $key = $clerk?->id ?? 0;
            $segments[$key] ??= ['clerk' => $clerk, 'total' => 0, 'work' => 0, 'peak' => 0, 'peak_work' => 0, 'hotspot' => 0, 'apps' => collect()];
            $total = (int) $apps->sum('total');
            $work = (int) $apps->sum('work');
            $segments[$key]['total'] += $total;
            $segments[$key]['work'] += $work;
            $segments[$key]['hotspot'] += (int) $apps->where('package', UsageReport::TETHERING)->sum('total');
            if ($this->isPeak($hour)) {
                $segments[$key]['peak'] += $total;
                $segments[$key]['peak_work'] += $work;
            }
            foreach ($apps as $app) {
                $segments[$key]['apps'][$app['label']] = ($segments[$key]['apps'][$app['label']] ?? 0) + $app['total'];
            }
        }

        $battery = $this->battery($device, $start, $end);
        $topSite = $this->usage->sites($device, $start, $end)->first();

        if ($segments === []) {
            $segments[0] = ['clerk' => $this->handovers->holderAt($device, $start->copy()->addHour()), 'total' => 0, 'work' => 0,
                'peak' => 0, 'peak_work' => 0, 'hotspot' => 0, 'apps' => collect()];
        }

        return collect($segments)->map(fn (array $s) => [
            'key' => $device->id.'-'.($s['clerk']?->id ?? 0),
            'asset_tag' => $device->asset_tag,
            'clerk' => $s['clerk']?->name ?? 'Nobody',
            'staff_number' => $s['clerk']?->staff_number,
            'total' => $s['total'],
            'work' => $s['work'],
            'peak' => $s['peak'],
            'peak_work' => $s['peak_work'],
            'off_peak' => $s['total'] - $s['peak'],
            'hotspot' => $s['hotspot'],
            'top_app' => $s['apps']->sortDesc()->keys()->first(),
            'top_site' => $topSite['host'] ?? null,
        ] + $battery)->values();
    }

    /** @return array{battery_start: ?int, battery_end: ?int, charged: bool, checkins: int, reliability: ?int} */
    private function battery(Device $device, Carbon $start, Carbon $end): array
    {
        $reports = StatusReport::query()->where('device_id', $device->id)
            ->whereBetween('reported_at', [$start, $end])->orderBy('reported_at')->get();
        // Phones check in every 15 minutes; each 15-minute slot with a report counts as "reported".
        $slots = max(1, (int) ceil($start->diffInMinutes(min($end, now())) / 15));
        $reported = $reports->groupBy(fn ($r) => intdiv($start->diffInMinutes($r->reported_at), 15))->count();

        return [
            'battery_start' => $reports->first()?->battery_percent,
            'battery_end' => $reports->last()?->battery_percent,
            'charged' => $reports->contains('battery_charging', true),
            'checkins' => $reports->count(),
            'reliability' => $start->isFuture() ? null : min(100, (int) round($reported / $slots * 100)),
        ];
    }
}
