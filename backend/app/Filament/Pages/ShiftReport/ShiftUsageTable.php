<?php

namespace App\Filament\Pages\ShiftReport;

use App\Mdm\Models\Shift;
use App\Mdm\Services\ShiftReport;
use App\Mdm\Services\UsageReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Per phone and clerk for one shift: data, work share, peak / off-peak, hotspot, top app/site, battery, reporting. */
class ShiftUsageTable extends TableWidget
{
    public ?int $shiftId = null;

    public ?string $date = null;

    protected static ?string $heading = 'Phones and clerks';

    public function table(Table $table): Table
    {
        $bytes = fn (int $state) => UsageReport::bytes($state);
        $share = fn (int $part, int $whole) => $whole ? round($part / $whole * 100).'%' : '—';

        return $table
            ->records(function () {
                $shift = Shift::find($this->shiftId);

                return $shift ? app(ShiftReport::class)->build($shift, $this->date)['rows']->keyBy('key')->all() : [];
            })
            ->paginated(false)
            ->emptyStateHeading('No data for this shift yet')
            ->columns([
                TextColumn::make('asset_tag')->label('Phone')->weight('bold'),
                TextColumn::make('clerk')->label('Clerk')->description(fn (array $record) => $record['staff_number']),
                TextColumn::make('total')->label('Data')->formatStateUsing($bytes)->alignEnd(),
                TextColumn::make('work')->label('Work')
                    ->formatStateUsing(fn (int $state, array $record) => $share($state, $record['total']))
                    ->badge()
                    ->color(fn (int $state, array $record) => $record['total'] && $state / $record['total'] < 0.5 ? 'warning' : 'success'),
                TextColumn::make('peak')->label('Peak')->formatStateUsing($bytes)->alignEnd(),
                TextColumn::make('off_peak')->label('Off-peak')->formatStateUsing($bytes)->alignEnd(),
                TextColumn::make('hotspot')->label('Hotspot')->formatStateUsing($bytes)->alignEnd()
                    ->color(fn (int $state) => $state > 0 ? 'warning' : null),
                TextColumn::make('top_app')->label('Top app')->placeholder('-'),
                TextColumn::make('top_site')->label('Top site')->placeholder('-')->toggleable(),
                TextColumn::make('battery_end')->label('Battery')
                    ->formatStateUsing(fn ($state, array $record) => $record['battery_start'] === null ? '-'
                        : "{$record['battery_start']}% → {$record['battery_end']}%".($record['charged'] ? ' ⚡' : ''))
                    ->placeholder('-'),
                TextColumn::make('reliability')->label('Reported')
                    ->formatStateUsing(fn ($state) => $state === null ? '-' : "{$state}%")
                    ->color(fn ($state) => $state !== null && $state < 80 ? 'warning' : null)
                    ->tooltip('Share of 15-minute periods in which the phone checked in'),
            ]);
    }
}
