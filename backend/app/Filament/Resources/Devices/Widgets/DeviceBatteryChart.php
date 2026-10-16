<?php

namespace App\Filament\Resources\Devices\Widgets;

use App\Mdm\Models\Device;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;

/** Battery level over time, from the status the phone reports at check-ins and on every change. */
class DeviceBatteryChart extends ChartWidget
{
    public ?Model $record = null;

    protected ?string $heading = 'Battery';

    protected ?string $maxHeight = '260px';

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
        $reports = $device->statusReports()
            ->where('reported_at', '>=', now()->subHours($this->filter === '7d' ? 168 : 24))
            ->whereNotNull('battery_percent')
            ->orderBy('reported_at')
            ->get(['battery_percent', 'battery_charging', 'reported_at']);

        return [
            'datasets' => [[
                'label' => 'Battery %',
                'data' => $reports->pluck('battery_percent')->all(),
                'borderColor' => '#2563eb',
                'backgroundColor' => 'rgba(37, 99, 235, 0.1)',
                'fill' => true,
                'tension' => 0.25,
                'pointRadius' => $reports->map(fn ($r) => $r->battery_charging ? 3 : 0)->all(),
                'pointBackgroundColor' => '#16a34a',
            ]],
            'labels' => $reports->map(fn ($r) => $r->reported_at->copy()->setTimezone($tz)->format($this->filter === '7d' ? 'D H:i' : 'H:i'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['min' => 0, 'max' => 100]],
            'plugins' => ['legend' => ['display' => false]],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
