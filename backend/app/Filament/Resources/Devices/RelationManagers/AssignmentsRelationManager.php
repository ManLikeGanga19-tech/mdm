<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only: who held this phone and when. Handovers are recorded on the Shift handover page. */
class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Handover history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('clerk.name')->label('Clerk')->description(fn ($record) => $record->clerk->staff_number),
                TextColumn::make('started_at')->label('From')->dateTime(),
                TextColumn::make('ended_at')->label('Until')->dateTime()->placeholder('Holding it now'),
                TextColumn::make('assignedBy.name')->label('Handed over by')->placeholder('-'),
                TextColumn::make('note')->placeholder('-')->wrap(),
            ]);
    }
}
