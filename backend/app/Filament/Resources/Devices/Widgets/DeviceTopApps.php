<?php

namespace App\Filament\Resources\Devices\Widgets;

use App\Mdm\Models\Device;
use App\Mdm\Services\UsageReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Model;

/** Apps by data used over the last 7 days, with the work share (whole app by rule, or estimated from its sites). */
class DeviceTopApps extends TableWidget
{
    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Apps by data used, last 7 days';

    public function table(Table $table): Table
    {
        /** @var Device $device */
        $device = $this->record;

        return $table
            ->records(fn () => app(UsageReport::class)
                ->apps($device, now()->subDays(7), now())
                ->take(15)
                ->mapWithKeys(fn (array $row) => [$row['package'] => $row])
                ->all())
            ->paginated(false)
            ->emptyStateHeading('No data use reported yet')
            ->emptyStateDescription('The phone uploads hourly figures at each check-in.')
            ->columns([
                TextColumn::make('label')->label('App')->weight('bold')->description(fn (array $record) => $record['package']),
                TextColumn::make('mobile')->label('Mobile')->formatStateUsing(fn (int $state) => UsageReport::bytes($state))->alignEnd(),
                TextColumn::make('wifi')->label('Wi-Fi')->formatStateUsing(fn (int $state) => UsageReport::bytes($state))->alignEnd(),
                TextColumn::make('work')
                    ->label('Work')
                    ->formatStateUsing(fn (int $state, array $record) => $record['total'] ? round($state / $record['total'] * 100).'%'.($record['estimated'] ? ' (est.)' : '') : '—')
                    ->badge()
                    ->color(fn (int $state, array $record) => $record['total'] && $state / $record['total'] >= 0.5 ? 'success' : 'gray')
                    ->alignEnd(),
            ]);
    }
}
