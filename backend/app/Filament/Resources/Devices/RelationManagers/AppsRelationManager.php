<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Mdm\Models\AppInventory;
use App\Mdm\Models\Device;
use App\Mdm\Services\CommandService;
use App\Mdm\Services\PolicyBuilder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The apps on this phone, and (super admins) blocking or allowing each one on this phone. */
class AppsRelationManager extends RelationManager
{
    protected static string $relationship = 'apps';

    protected static ?string $title = 'Apps';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var Device $device */
        $device = $this->getOwnerRecord();
        $blocked = fn () => app(PolicyBuilder::class)->build($device->fresh())['blocked_apps'];

        return $table
            ->defaultSort('label')
            ->emptyStateHeading('No app list yet')
            ->emptyStateDescription('The phone uploads its app list at its next check-in.')
            ->columns([
                TextColumn::make('label')->label('App')->searchable()->sortable()->weight('bold')
                    ->description(fn (AppInventory $record) => $record->package_name),
                TextColumn::make('version_name')->label('Version')->placeholder('-'),
                TextColumn::make('status')
                    ->state(fn (AppInventory $record) => in_array($record->package_name, $blocked(), true) ? 'Blocked' : 'Allowed')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Blocked' ? 'danger' : 'success'),
                TextColumn::make('system')->label('Type')->formatStateUsing(fn (bool $state) => $state ? 'Built-in' : 'Installed')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('system')->label('Built-in apps')
                    ->queries(true: fn (Builder $query) => $query->where('system', true), false: fn (Builder $query) => $query->where('system', false)),
            ])
            ->recordActions([
                Action::make('block')
                    ->label('Block')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (AppInventory $record) => "{$record->label} will be greyed out on {$device->asset_tag} within seconds.")
                    ->visible(fn (AppInventory $record) => auth()->user()->isSuperAdmin()
                        && $record->package_name !== 'com.noahport.mdm.agent'
                        && ! in_array($record->package_name, $blocked(), true))
                    ->action(function (AppInventory $record) use ($device) {
                        app(CommandService::class)->setAppBlocked($device, $record->package_name, true);
                        Notification::make()->title("{$record->label} blocked on {$device->asset_tag}")->success()->send();
                    }),
                Action::make('allow')
                    ->label('Allow')
                    ->color('success')
                    ->visible(fn (AppInventory $record) => auth()->user()->isSuperAdmin() && in_array($record->package_name, $blocked(), true))
                    ->action(function (AppInventory $record) use ($device) {
                        app(CommandService::class)->setAppBlocked($device, $record->package_name, false);
                        Notification::make()->title("{$record->label} allowed on {$device->asset_tag}")->success()->send();
                    }),
            ]);
    }
}
