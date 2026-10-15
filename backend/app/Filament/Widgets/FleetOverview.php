<?php

namespace App\Filament\Widgets;

use App\Mdm\Models\Command;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceAssignment;
use App\Mdm\Services\FleetHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/** Fleet KPIs: enrollment, reporting, rule compliance, clerk coverage, command outcomes, security updates. */
class FleetOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->canViewFleet() ?? false;
    }

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $health = app(FleetHealth::class);
        $fleet = Device::query()->where('state', '!=', Device::STATE_RETIRED)->get();
        $enrolled = $fleet->where('state', Device::STATE_ENROLLED);
        $pending = $fleet->count() - $enrolled->count();

        $reporting = $enrolled->filter(fn (Device $d) => $health->isReporting($d))->count();
        $applied = $enrolled->filter(fn (Device $d) => $health->rulesApplied($d))->count();
        $oldPatch = $enrolled->filter(fn (Device $d) => $health->patchIsOld($d))->count();

        $held = DeviceAssignment::query()->whereNull('ended_at')->count();
        $handoversToday = DeviceAssignment::query()->where('started_at', '>=', $this->startOfToday())->count();

        $commands = Command::query()->where('created_at', '>=', now()->subDay());
        $sent = (clone $commands)->count();
        $failed = (clone $commands)->whereIn('status', ['failed', 'expired'])->count();
        $waiting = Command::query()->whereIn('status', ['queued', 'delivered'])->count();

        return [
            Stat::make('Phones enrolled', "{$enrolled->count()} of {$fleet->count()}")
                ->description($pending ? "{$pending} waiting for enrollment" : 'Whole fleet enrolled')
                ->descriptionIcon($pending ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
                ->color($pending ? 'warning' : 'success'),
            Stat::make('Reporting now', $this->ratio($reporting, $enrolled->count()))
                ->description($enrolled->count() - $reporting
                    ? ($enrolled->count() - $reporting).' silent for over '.FleetHealth::SILENT_AFTER_MINUTES.' min'
                    : 'All checked in within the hour')
                ->descriptionIcon('heroicon-m-signal')
                ->color($this->band($reporting, $enrolled->count())),
            Stat::make('Rules applied', $this->ratio($applied, $enrolled->count()))
                ->description($enrolled->count() - $applied ? ($enrolled->count() - $applied).' waiting to apply the latest rules' : 'Hotspot and app rules in force')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color($this->band($applied, $enrolled->count())),
            Stat::make('Held by a clerk', "{$held} of {$fleet->count()}")
                ->description("{$handoversToday} ".str('handover')->plural($handoversToday).' today')
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->chart($this->daily(DeviceAssignment::query()->where('started_at', '>=', now()->subDays(7))->pluck('started_at')))
                ->color('primary'),
            Stat::make('Commands, last 24 h', $sent)
                ->description($failed ? "{$failed} failed · {$waiting} waiting" : ($waiting ? "{$waiting} waiting for the phone" : 'All confirmed'))
                ->descriptionIcon($failed ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-command-line')
                ->chart($this->daily(Command::query()->where('created_at', '>=', now()->subDays(7))->pluck('created_at')))
                ->color($failed ? 'danger' : 'primary'),
            Stat::make('Security updates', $oldPatch ? "{$oldPatch} out of date" : 'Up to date')
                ->description('Android patch older than '.FleetHealth::PATCH_MAX_AGE_DAYS.' days')
                ->descriptionIcon('heroicon-m-lock-closed')
                ->color($oldPatch ? 'warning' : 'success'),
        ];
    }

    private function ratio(int $part, int $whole): string
    {
        return $whole ? sprintf('%d of %d (%d%%)', $part, $whole, round($part / $whole * 100)) : '—';
    }

    private function band(int $part, int $whole): string
    {
        if (! $whole) {
            return 'gray';
        }

        return match (true) {
            $part === $whole => 'success',
            $part / $whole >= 0.8 => 'warning',
            default => 'danger',
        };
    }

    /** Counts per day for the last 7 days (admin's time zone), for the sparkline. */
    private function daily(Collection $timestamps): array
    {
        $tz = config('app.display_timezone');
        $byDay = $timestamps->countBy(fn ($t) => $t->copy()->setTimezone($tz)->toDateString());

        return collect(range(6, 0))
            ->map(fn (int $daysAgo) => $byDay[now($tz)->subDays($daysAgo)->toDateString()] ?? 0)
            ->all();
    }

    private function startOfToday()
    {
        return now(config('app.display_timezone'))->startOfDay()->utc();
    }
}
