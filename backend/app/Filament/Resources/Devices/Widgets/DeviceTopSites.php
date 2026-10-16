<?php

namespace App\Filament\Resources\Devices\Widgets;

use App\Mdm\Models\Device;
use App\Mdm\Services\UsageReport;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Model;

/**
 * Websites and services by estimated data, last 7 days, marked Work / Not work (see UsageReport for the estimate).
 * Phone housekeeping (BackgroundTraffic) is hidden unless the admin asks for it.
 */
class DeviceTopSites extends TableWidget
{
    public ?Model $record = null;

    public bool $showBackground = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        /** @var Device $device */
        $device = $this->record;
        $report = app(UsageReport::class);

        return $table
            ->heading($this->showBackground ? 'Websites and services, last 7 days' : 'Websites visited, last 7 days')
            ->records(fn () => $report
                ->sites($device, now()->subDays(7), now())
                ->when(! $this->showBackground, fn ($sites) => $sites->where('background', false))
                ->take(25)
                ->mapWithKeys(fn (array $row) => [$row['package'].'|'.$row['host'] => $row])
                ->all())
            ->paginated(false)
            ->description($report->websiteFreshness($device).' Data per website is an estimate: Android only records data per app, so each app\'s data is shared across the sites it opened in the same hour.')
            ->headerActions([
                Action::make('background')
                    ->label(fn () => $this->showBackground ? 'Hide background services' : 'Show background services')
                    ->tooltip('Play Store and Google updates, connection checks, ad and analytics beacons, and this MDM')
                    ->link()
                    ->action(fn () => $this->showBackground = ! $this->showBackground),
            ])
            ->emptyStateHeading('No websites recorded yet')
            ->emptyStateDescription('Android sends these in batches, from a few minutes to about 1.5 hours apart.')
            ->columns([
                TextColumn::make('host')->label('Website / service')->weight('bold')->description(fn (array $record) => $record['label'])->wrap(),
                TextColumn::make('bytes')->label('Data (est.)')->formatStateUsing(fn (int $state) => UsageReport::bytes($state))->alignEnd(),
                TextColumn::make('lookups')->label('Visits')->alignEnd(),
                TextColumn::make('work')
                    ->label('')
                    ->formatStateUsing(fn (bool $state, array $record) => $state ? ($record['work_label'] ?? 'Work') : ($record['background'] ? 'Background' : 'Not work'))
                    ->badge()
                    ->color(fn (bool $state, array $record) => $state ? 'success' : ($record['background'] ? 'info' : 'gray')),
            ]);
    }
}
