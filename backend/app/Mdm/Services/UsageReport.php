<?php

namespace App\Mdm\Services;

use App\Mdm\Models\AppInventory;
use App\Mdm\Models\Device;
use App\Mdm\Models\NetworkLog;
use App\Mdm\Models\UsageSample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Data use for one phone over a time window, from hourly usage samples and network logs.
 *
 * Android records data per app, not per website. For apps that visit many sites (Chrome, and the company PWA that
 * runs inside it) the per-site figures are an ESTIMATE: each hour's data for the app is split across the sites it
 * looked up in that hour, in proportion to the number of lookups. Work/not-work comes from WorkClassifier.
 */
class UsageReport
{
    public const TETHERING = '__tethering__';

    public function __construct(private WorkClassifier $work) {}

    /** @return Collection<int, array{hour: Carbon, mobile: int, wifi: int, hotspot: int}> hourly totals */
    public function hourly(Device $device, Carbon $from, Carbon $to): Collection
    {
        return $this->samples($device, $from, $to)
            ->groupBy(fn (UsageSample $s) => $s->period_start->timestamp)
            ->map(fn (Collection $hour) => [
                'hour' => $hour->first()->period_start,
                'mobile' => (int) $hour->where('network_type', 'mobile')->where('package_name', '!=', self::TETHERING)->sum(fn ($s) => $s->rx_bytes + $s->tx_bytes),
                'wifi' => (int) $hour->where('network_type', 'wifi')->sum(fn ($s) => $s->rx_bytes + $s->tx_bytes),
                'hotspot' => (int) $hour->where('package_name', self::TETHERING)->sum(fn ($s) => $s->rx_bytes + $s->tx_bytes),
            ])
            ->sortBy('hour')
            ->values();
    }

    /**
     * Per app: mobile, Wi-Fi, total, and how much of it was work (whole app by app rule, or estimated from its sites).
     *
     * @return Collection<int, array{package: string, label: string, mobile: int, wifi: int, total: int, work: int, estimated: bool}>
     */
    public function apps(Device $device, Carbon $from, Carbon $to): Collection
    {
        $labels = $this->labels($device);
        $sites = $this->sites($device, $from, $to)->groupBy('package');

        return $this->samples($device, $from, $to)
            ->groupBy('package_name')
            ->map(function (Collection $rows, string $package) use ($labels, $sites) {
                $mobile = (int) $rows->where('network_type', 'mobile')->sum(fn ($s) => $s->rx_bytes + $s->tx_bytes);
                $wifi = (int) $rows->where('network_type', 'wifi')->sum(fn ($s) => $s->rx_bytes + $s->tx_bytes);
                $appSites = $sites->get($package, collect());
                $work = match (true) {
                    $this->work->isWorkApp($package) => $mobile + $wifi,
                    $appSites->isNotEmpty() => (int) $appSites->where('work', true)->sum('bytes'),
                    default => 0,
                };

                return [
                    'package' => $package,
                    'label' => $labels[$package] ?? $this->sentinelLabel($package),
                    'mobile' => $mobile,
                    'wifi' => $wifi,
                    'total' => $mobile + $wifi,
                    'work' => min($work, $mobile + $wifi),
                    'estimated' => ! $this->work->isWorkApp($package) && $appSites->isNotEmpty(),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Per site and app: lookups and ESTIMATED bytes (see class doc), with work classification.
     *
     * @return Collection<int, array{host: string, package: ?string, label: string, lookups: int, bytes: int, work: bool, work_label: ?string}>
     */
    public function sites(Device $device, Carbon $from, Carbon $to): Collection
    {
        $lookups = NetworkLog::query()
            ->where('device_id', $device->id)
            ->where('log_type', 'dns')
            ->whereBetween('occurred_at', [$from, $to])
            ->whereNotNull('host')
            ->get(['package_name', 'host', 'occurred_at']);

        // Bytes per app per hour, to share out across that hour's lookups.
        $bytes = $this->samples($device, $from, $to)
            ->groupBy(fn (UsageSample $s) => $s->package_name.'|'.$s->period_start->timestamp)
            ->map(fn (Collection $rows) => (int) $rows->sum(fn ($s) => $s->rx_bytes + $s->tx_bytes));

        $estimated = [];
        foreach ($lookups->groupBy(fn (NetworkLog $l) => $l->package_name.'|'.$l->occurred_at->copy()->startOfHour()->timestamp) as $key => $hourLookups) {
            $hourBytes = $bytes[$key] ?? 0;
            $total = $hourLookups->count();
            foreach ($hourLookups->countBy('host') as $host => $count) {
                $siteKey = $hourLookups->first()->package_name.'|'.$host;
                $estimated[$siteKey] ??= ['package' => $hourLookups->first()->package_name, 'host' => $host, 'lookups' => 0, 'bytes' => 0];
                $estimated[$siteKey]['lookups'] += $count;
                $estimated[$siteKey]['bytes'] += intdiv($hourBytes * $count, $total);
            }
        }

        $labels = $this->labels($device);

        return collect($estimated)
            ->map(fn (array $site) => $site + [
                'label' => $labels[$site['package']] ?? $site['package'] ?? 'Unknown app',
                'work' => $this->work->isWorkHost($site['host']) || $this->work->isWorkApp($site['package']),
                'work_label' => $this->work->labelFor($site['package'], $site['host']),
            ])
            ->sortByDesc(fn (array $s) => [$s['bytes'], $s['lookups']])
            ->values();
    }

    /** @return array{total: int, work: int} */
    public function totals(Device $device, Carbon $from, Carbon $to): array
    {
        $apps = $this->apps($device, $from, $to);

        return ['total' => (int) $apps->sum('total'), 'work' => (int) $apps->sum('work')];
    }

    private function samples(Device $device, Carbon $from, Carbon $to): Collection
    {
        return UsageSample::query()
            ->where('device_id', $device->id)
            ->where('period_start', '>=', $from)
            ->where('period_start', '<', $to)
            ->get();
    }

    /** @return array<string, string> package => app name, from this phone's app list (then any phone's). */
    private function labels(Device $device): array
    {
        return AppInventory::query()->whereNotNull('label')
            ->orderByRaw('device_id = ? desc', [$device->id])
            ->get(['package_name', 'label'])
            ->unique('package_name')
            ->pluck('label', 'package_name')
            ->all();
    }

    private function sentinelLabel(string $package): string
    {
        return match (true) {
            $package === self::TETHERING => 'Hotspot (shared with other devices)',
            $package === '__removed__' => 'Uninstalled apps',
            str_starts_with($package, 'android.uid.system') => 'Android system',
            str_starts_with($package, '__uid_') => 'Android system',
            default => $package,
        };
    }

    public static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 3 => number_format($bytes / 1024 ** 3, 2).' GB',
            $bytes >= 1024 ** 2 => number_format($bytes / 1024 ** 2, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
