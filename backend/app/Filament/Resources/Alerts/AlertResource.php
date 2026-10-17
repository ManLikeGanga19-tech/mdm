<?php

namespace App\Filament\Resources\Alerts;

use App\Filament\Resources\Alerts\Pages\ManageAlerts;
use App\Filament\Resources\Devices\DeviceResource;
use App\Mdm\Models\Alert;
use App\Mdm\Models\AlertRule;
use App\Mdm\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Alerts raised by the alert rules. Anyone who sees the fleet sees them and can acknowledge them. */
class AlertResource extends Resource
{
    protected static ?string $model = Alert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermission(Permission::FleetView) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Alert::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function acknowledge(Collection $alerts): void
    {
        Alert::query()->whereKey($alerts->pluck('id')->all())->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now(), 'acknowledged_by' => auth()->id()]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('triggered_at', 'desc')
            ->columns([
                TextColumn::make('triggered_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('rule.name')->label('Alert'),
                TextColumn::make('device.asset_tag')->label('Phone')->placeholder('-')
                    ->url(fn (Alert $record) => $record->device ? DeviceResource::getUrl('view', ['record' => $record->device]) : null),
                TextColumn::make('message')->wrap(),
                TextColumn::make('status')->state(fn (Alert $record) => $record->status())->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Open' => 'danger', 'Cleared' => 'success', default => 'gray',
                    })
                    ->description(fn (Alert $record) => match (true) {
                        $record->acknowledged_at !== null => 'by '.($record->acknowledgedBy?->name ?? 'someone'),
                        $record->resolved_at !== null => $record->resolved_at->setTimezone(config('app.display_timezone'))->format('D H:i'),
                        default => null,
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['open' => 'Open', 'cleared' => 'Cleared', 'acknowledged' => 'Acknowledged'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'open' => $query->open(),
                        'cleared' => $query->whereNotNull('resolved_at')->whereNull('acknowledged_at'),
                        'acknowledged' => $query->whereNotNull('acknowledged_at'),
                        default => $query,
                    }),
                SelectFilter::make('rule_id')->label('Alert')->options(fn () => AlertRule::query()->orderBy('id')->pluck('name', 'id')),
            ])
            ->recordActions([
                Action::make('acknowledge')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (Alert $record) => $record->acknowledged_at === null)
                    ->action(fn (Alert $record) => self::acknowledge(collect([$record]))),
            ])
            ->toolbarActions([
                BulkAction::make('acknowledge')
                    ->icon(Heroicon::OutlinedCheck)
                    ->action(fn (Collection $records) => self::acknowledge($records))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAlerts::route('/'),
        ];
    }
}
