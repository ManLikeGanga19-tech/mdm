<?php

namespace App\Filament\Pages\ShiftReport;

use App\Mdm\Models\Shift;
use App\Mdm\Services\ShiftReport;
use App\Mdm\Services\UsageReport;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Headline numbers for one shift: handovers on time, data used, work share, peak share, hotspot. */
class ShiftSummary extends StatsOverviewWidget
{
    public ?int $shiftId = null;

    public ?string $date = null;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $shift = Shift::find($this->shiftId);
        if (! $shift || ! $this->date) {
            return [];
        }
        $report = app(ShiftReport::class)->build($shift, $this->date);
        $t = $report['totals'];
        $handovers = $report['handovers'];
        $done = $handovers->whereIn('status', ['On time', 'Early'])->count();
        $late = $handovers->where('status', 'Late')->count();
        $missing = $handovers->where('status', 'Missing')->count();

        return [
            Stat::make('Handovers on time', "{$done} of {$handovers->count()}")
                ->description($missing || $late ? trim(($late ? "{$late} late " : '').($missing ? "{$missing} missing" : '')) : 'All phones handed over in the window')
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->color($missing ? 'danger' : ($late ? 'warning' : 'success')),
            Stat::make('Data used', UsageReport::bytes($t['total']))
                ->description('Hotspot: '.UsageReport::bytes($t['hotspot']))
                ->descriptionIcon('heroicon-m-signal'),
            Stat::make('Work', $t['total'] ? round($t['work'] / $t['total'] * 100).'%' : '—')
                ->description(UsageReport::bytes($t['total'] - $t['work']).' not work')
                ->descriptionIcon('heroicon-m-briefcase')
                ->color($t['total'] && $t['work'] / $t['total'] < 0.5 ? 'warning' : 'success'),
            Stat::make('Peak hours', $t['total'] ? round($t['peak'] / $t['total'] * 100).'%' : '—')
                ->description(UsageReport::bytes($t['peak']).' in peak, '.UsageReport::bytes($t['total'] - $t['peak']).' off-peak')
                ->descriptionIcon('heroicon-m-clock'),
        ];
    }
}
