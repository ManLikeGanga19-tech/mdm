<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Mdm\Models\NetworkLog;
use App\Mdm\Services\BackgroundTraffic;
use App\Mdm\Services\UsageReport;
use App\Mdm\Services\WorkClassifier;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every website/service lookup the phone made, newest first, with the app and work classification. Phone housekeeping
 * (BackgroundTraffic) is filtered out by default.
 */
class NetworkLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'networkLogs';

    protected static ?string $title = 'Website log';

    public bool $showBackground = false;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $work = app(WorkClassifier::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('log_type', 'dns')->whereNotNull('host')
                ->when(! $this->showBackground, fn (Builder $query) => app(BackgroundTraffic::class)->excludeFrom($query)))
            ->defaultSort('occurred_at', 'desc')
            ->description(app(UsageReport::class)->websiteFreshness($this->getOwnerRecord()))
            ->headerActions([
                Action::make('background')
                    ->label(fn () => $this->showBackground ? 'Hide background services' : 'Show background services')
                    ->tooltip('Play Store and Google updates, connection checks, ad and analytics beacons, and this MDM')
                    ->link()
                    ->action(fn () => $this->showBackground = ! $this->showBackground),
            ])
            ->emptyStateHeading('No website lookups recorded yet')
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M H:i:s')->sortable(),
                TextColumn::make('host')->label('Website / service')->searchable()->weight('bold'),
                TextColumn::make('package_name')->label('App')->searchable()->placeholder('-'),
                TextColumn::make('work')
                    ->label('')
                    ->state(fn (NetworkLog $record) => $work->isWorkHost($record->host) || $work->isWorkApp($record->package_name) ? 'Work' : 'Not work')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Work' ? 'success' : 'gray'),
            ])
            ->filters([
                Filter::make('today')->label('Today only')
                    ->query(fn (Builder $query) => $query->where('occurred_at', '>=', now(config('app.display_timezone'))->startOfDay()->utc())),
                SelectFilter::make('package_name')->label('App')
                    ->options(fn () => $this->getOwnerRecord()->networkLogs()->where('log_type', 'dns')->distinct()->orderBy('package_name')->pluck('package_name', 'package_name')->filter()->all()),
            ]);
    }
}
