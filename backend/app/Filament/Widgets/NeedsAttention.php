<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Devices\DeviceResource;
use App\Mdm\Models\Device;
use App\Mdm\Services\FleetHealth;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Phones with a problem and what the problem is (FleetHealth). Empty = all good. */
class NeedsAttention extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Needs attention';

    public static function canView(): bool
    {
        return auth()->user()?->canViewFleet() ?? false;
    }

    public function table(Table $table): Table
    {
        $health = app(FleetHealth::class);
        // The fleet is small (6 phones), so issues are worked out per phone in PHP.
        $ids = Device::query()->where('state', '!=', Device::STATE_RETIRED)->get()
            ->filter(fn (Device $d) => $health->issues($d) !== [])
            ->pluck('id');

        return $table
            ->query(Device::query()->whereKey($ids)->with('currentAssignment.clerk'))
            ->defaultSort('asset_tag')
            ->paginated(false)
            ->emptyStateHeading('Nothing needs attention')
            ->emptyStateDescription('Every phone is enrolled, reporting and running the latest rules.')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->recordUrl(fn (Device $record) => DeviceResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('asset_tag')->label('Phone')->weight('bold'),
                TextColumn::make('issues')
                    ->label('Issue')
                    ->state(fn (Device $record) => $health->issues($record))
                    ->badge()
                    ->color(fn (string $state) => str_starts_with($state, 'Silent') || str_starts_with($state, 'A command') || $state === 'Never checked in' ? 'danger' : 'warning'),
                TextColumn::make('currentAssignment.clerk.name')->label('Held by')->placeholder('Nobody'),
                TextColumn::make('last_checkin_at')->label('Last check-in')->since()->placeholder('Never'),
            ]);
    }
}
