<?php

namespace App\Filament\Widgets;

use App\Mdm\Models\Command;
use App\Mdm\Models\DeviceAssignment;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;

/** Daily handovers and commands over the last 14 days (admin's time zone). */
class HandoversChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Activity, last 14 days';

    protected ?string $description = 'Shift handovers recorded and commands sent per day';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return auth()->user()?->canViewFleet() ?? false;
    }

    protected function getData(): array
    {
        $tz = config('app.display_timezone');
        $days = collect(range(13, 0))->map(fn (int $ago) => now($tz)->subDays($ago)->startOfDay());
        $since = $days->first()->copy()->utc();

        return [
            'datasets' => [
                [
                    'label' => 'Handovers',
                    'data' => $this->perDay(DeviceAssignment::query()->where('started_at', '>=', $since)->pluck('started_at'), $days),
                    'backgroundColor' => '#2563eb',
                    'borderRadius' => 3,
                ],
                [
                    'label' => 'Commands',
                    'data' => $this->perDay(Command::query()->where('created_at', '>=', $since)->pluck('created_at'), $days),
                    'backgroundColor' => '#94a3b8',
                    'borderRadius' => 3,
                ],
            ],
            'labels' => $days->map(fn ($d) => $d->format('D j'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    private function perDay(Collection $timestamps, Collection $days): array
    {
        $tz = config('app.display_timezone');
        $counts = $timestamps->countBy(fn ($t) => $t->copy()->setTimezone($tz)->toDateString());

        return $days->map(fn ($d) => $counts[$d->toDateString()] ?? 0)->all();
    }
}
