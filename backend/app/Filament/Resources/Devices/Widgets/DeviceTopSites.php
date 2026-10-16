<?php

namespace App\Filament\Resources\Devices\Widgets;

use App\Mdm\Models\Device;
use App\Mdm\Services\UsageReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Model;

/** Websites and services by estimated data, last 7 days, marked Work / Not work (see UsageReport for the estimate). */
class DeviceTopSites extends TableWidget
{
    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Websites and services, last 7 days';

    public function table(Table $table): Table
    {
        /** @var Device $device */
        $device = $this->record;

        return $table
            ->records(fn () => app(UsageReport::class)
                ->sites($device, now()->subDays(7), now())
                ->take(20)
                ->mapWithKeys(fn (array $row) => [$row['package'].'|'.$row['host'] => $row])
                ->all())
            ->paginated(false)
            ->description('Data per website is an estimate: Android only records data per app, so each app\'s data is shared across the sites it opened in the same hour.')
            ->emptyStateHeading('No websites recorded yet')
            ->emptyStateDescription('Android sends these in batches, from a few minutes to about 1.5 hours apart.')
            ->columns([
                TextColumn::make('host')->label('Website / service')->weight('bold')->description(fn (array $record) => $record['label'])->wrap(),
                TextColumn::make('bytes')->label('Data (est.)')->formatStateUsing(fn (int $state) => UsageReport::bytes($state))->alignEnd(),
                TextColumn::make('lookups')->label('Visits')->alignEnd(),
                TextColumn::make('work')
                    ->label('')
                    ->formatStateUsing(fn (bool $state, array $record) => $state ? ($record['work_label'] ?? 'Work') : 'Not work')
                    ->badge()
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ]);
    }
}
