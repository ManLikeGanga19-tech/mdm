<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Mdm\Models\NetworkLog;
use App\Mdm\Services\WorkClassifier;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Every website/service lookup the phone made, newest first, with the app and work classification. */
class NetworkLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'networkLogs';

    protected static ?string $title = 'Website log';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $work = app(WorkClassifier::class);

        return $table
            ->modifyQueryUsing(fn (Builder $q) => $q->where('log_type', 'dns')->whereNotNull('host'))
            ->defaultSort('occurred_at', 'desc')
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
                    ->query(fn (Builder $q) => $q->where('occurred_at', '>=', now(config('app.display_timezone'))->startOfDay()->utc())),
                SelectFilter::make('package_name')->label('App')
                    ->options(fn () => $this->getOwnerRecord()->networkLogs()->where('log_type', 'dns')->distinct()->orderBy('package_name')->pluck('package_name', 'package_name')->filter()->all()),
            ]);
    }
}
