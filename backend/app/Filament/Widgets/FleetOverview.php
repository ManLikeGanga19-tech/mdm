<?php

namespace App\Filament\Widgets;

use App\Mdm\Models\Command;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceAssignment;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Dashboard summary: how many phones, how many reporting, who holds them, what is pending. */
class FleetOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        return auth()->user()?->canViewFleet() ?? false;
    }

    protected function getStats(): array
    {
        $active = Device::query()->where('state', '!=', Device::STATE_RETIRED);
        $total = (clone $active)->count();
        $enrolled = (clone $active)->where('state', Device::STATE_ENROLLED)->count();
        $recent = (clone $active)->where('last_checkin_at', '>=', now()->subMinutes(30))->count();
        $held = DeviceAssignment::query()->whereNull('ended_at')->count();
        $pending = Command::query()->whereIn('status', ['queued', 'delivered'])->count();

        return [
            Stat::make('Phones', $total)->description("{$enrolled} enrolled"),
            Stat::make('Checked in, last 30 min', $recent)
                ->description($enrolled - $recent > 0 ? ($enrolled - $recent).' not reporting' : 'All reporting')
                ->color($enrolled - $recent > 0 ? 'warning' : 'success'),
            Stat::make('With a clerk now', $held)->description('From the shift handover'),
            Stat::make('Commands waiting', $pending)->description('Queued or not yet confirmed'),
        ];
    }
}
