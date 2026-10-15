<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ShiftHandover;
use App\Mdm\Models\Device;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Who has which phone right now. Shown to every role, including shift coordinators. */
class HoldersNow extends TableWidget
{
    protected static ?int $sort = 4;

    protected static ?string $heading = 'Who has which phone';

    public function table(Table $table): Table
    {
        return $table
            ->query(Device::query()->where('state', '!=', Device::STATE_RETIRED)->with('currentAssignment.clerk'))
            ->defaultSort('asset_tag')
            ->paginated(false)
            ->emptyStateHeading('No phones registered yet')
            ->headerActions([
                Action::make('handover')
                    ->label('Shift handover')
                    ->link()
                    ->url(ShiftHandover::getUrl())
                    ->visible(fn () => ShiftHandover::canAccess()),
            ])
            ->columns([
                TextColumn::make('asset_tag')->label('Phone')->weight('bold'),
                TextColumn::make('currentAssignment.clerk.name')
                    ->label('Held by')
                    ->placeholder('Nobody')
                    ->description(fn (Device $record) => $record->currentAssignment?->clerk?->staff_number),
                TextColumn::make('currentAssignment.started_at')->label('Since')->since()->placeholder('-'),
            ]);
    }
}
