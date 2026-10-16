<?php

namespace App\Filament\Resources\Devices\Widgets;

use App\Mdm\Models\Device;
use App\Mdm\Services\UsageReport;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;

/** Hourly data use for one phone: mobile, Wi-Fi and hotspot, last 24 hours or 7 days (admin's time zone). */
class DeviceUsageChart extends ChartWidget
{
    public ?Model $record = null;

    protected ?string $heading = 'Data use';

    protected ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '24h';

    protected function getFilters(): ?array
    {
        return ['24h' => 'Last 24 hours', '7d' => 'Last 7 days'];
    }

    protected function getData(): array
    {
        /** @var Device $device */
        $device = $this->record;
        $tz = config('app.display_timezone');
        $days = $this->filter === '7d';
        $to = now()->startOfHour()->addHour();
        $from = $to->copy()->subHours($days ? 24 * 7 : 24);

        $hourly = app(UsageReport::class)->hourly($device, $from, $to);
        // 24 h: one bar per hour; 7 days: one bar per day.
        $buckets = collect();
        for ($t = $from->copy(); $t->lt($to); $days ? $t->addDay() : $t->addHour()) {
            $buckets[$t->timestamp] = ['label' => $t->copy()->setTimezone($tz)->format($days ? 'D j' : 'H:00'), 'mobile' => 0, 'wifi' => 0, 'hotspot' => 0];
        }
        foreach ($hourly as $h) {
            $key = $days
                ? $from->copy()->addDays((int) floor($from->diffInHours($h['hour']) / 24))->timestamp
                : $h['hour']->timestamp;
            if (! isset($buckets[$key])) {
                continue;
            }
            $b = $buckets[$key];
            foreach (['mobile', 'wifi', 'hotspot'] as $k) {
                $b[$k] += $h[$k];
            }
            $buckets[$key] = $b;
        }
        $mb = fn (string $k) => $buckets->map(fn ($b) => round($b[$k] / 1048576, 1))->values()->all();

        return [
            'datasets' => [
                ['label' => 'Mobile data (MB)', 'data' => $mb('mobile'), 'backgroundColor' => '#2563eb', 'stack' => 'a'],
                ['label' => 'Wi-Fi (MB)', 'data' => $mb('wifi'), 'backgroundColor' => '#93c5fd', 'stack' => 'a'],
                ['label' => 'Shared via hotspot (MB)', 'data' => $mb('hotspot'), 'backgroundColor' => '#f59e0b', 'stack' => 'a'],
            ],
            'labels' => $buckets->pluck('label')->values()->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true, 'beginAtZero' => true]],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
