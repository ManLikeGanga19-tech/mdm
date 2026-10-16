<?php

namespace App\Filament\Widgets;

use App\Mdm\Models\CrewHandover;
use App\Mdm\Permission;
use App\Mdm\Services\CrewHandoverService;
use App\Mdm\Services\RosterService;
use App\Mdm\Services\ShiftReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;

/**
 * Dashboard: the crew-to-crew handovers of the last two days, newest first, with how they went (on time, late,
 * pending, disputed, missing). Refreshes every 15 s.
 */
class CrewHandovers extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (auth()->user()?->hasPermission(Permission::FleetView) || auth()->user()?->hasPermission(Permission::ReportsView)) ?? false;
    }

    public function table(Table $table): Table
    {
        $tz = config('app.display_timezone');
        $time = fn (?Carbon $at) => $at?->copy()->setTimezone($tz)->format('H:i');

        return $table
            ->heading('Crew handovers')
            ->description('Outgoing crew hands the phones over, incoming crew accepts them.')
            ->poll('15s')
            ->paginated(false)
            ->records(function () use ($time) {
                $roster = app(RosterService::class);
                $handovers = app(CrewHandoverService::class);
                $tz = config('app.display_timezone');
                $rows = [];
                foreach ($roster->shifts() as $shift) {
                    foreach ([-1, 0, 1] as $offset) {
                        $date = now($tz)->addDays($offset)->toDateString();
                        [$start, $end] = $shift->handoverWindow($date);
                        if ($start->copy()->subMinutes(ShiftReport::EARLY_GRACE_MINUTES)->gt(now()) || $start->lt(now()->subDays(2))) {
                            continue;
                        }
                        $handover = CrewHandover::query()->with(['handedBy', 'acceptedBy', 'items'])
                            ->where('shift_id', $shift->id)->whereDate('date', $date)->first();
                        $rows["{$shift->id}-{$date}"] = [
                            'sort' => $start->timestamp,
                            'when' => $shift->name.' · '.Carbon::parse($date, $tz)->format('D j M').' '.$time($start).'–'.$time($end),
                            'crews' => ($roster->previous($shift, $date)['crew']?->name ?? '?').' → '.($roster->crewFor($shift, $date)?->name ?? 'no crew on roster'),
                            'handed' => $handover ? $time($handover->handed_at).' · '.($handover->handedBy?->name ?? '?').' · '.$handover->items->count().' phones' : null,
                            'accepted' => $handover?->accepted_at ? $time($handover->accepted_at).' · '.($handover->acceptedBy?->name ?? '?') : null,
                            'status' => $handovers->status($shift, $date, $handover),
                        ];
                    }
                }

                return collect($rows)->sortByDesc('sort')->take(6)->all();
            })
            ->columns([
                TextColumn::make('when')->label('Shift'),
                TextColumn::make('crews')->label('From → to'),
                TextColumn::make('handed')->label('Handed over')->placeholder('—'),
                TextColumn::make('accepted')->label('Accepted')->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'on time' => 'success', 'pending', 'late' => 'warning', 'missing', 'disputed' => 'danger', default => 'gray',
                    }),
            ])
            ->emptyStateHeading('No handovers yet')
            ->emptyStateDescription('Set up Crews and the Roster first.');
    }
}
