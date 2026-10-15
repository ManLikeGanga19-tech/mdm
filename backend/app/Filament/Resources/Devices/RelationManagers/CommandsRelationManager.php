<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Mdm\Models\Command;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only history of commands sent to this phone. Commands are created from the page's header buttons. */
class CommandsRelationManager extends RelationManager
{
    protected static string $relationship = 'commands';

    protected static ?string $title = 'Commands';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('created_at')->label('Sent')->dateTime()->sortable(),
                TextColumn::make('type')->label('Command')->formatStateUsing(fn (Command $record) => $record->label()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'succeeded' => 'success',
                        'failed', 'expired' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('requestedBy.name')->label('By')->placeholder('-'),
                TextColumn::make('completed_at')->label('Finished')->dateTime()->placeholder('-'),
            ]);
    }
}
