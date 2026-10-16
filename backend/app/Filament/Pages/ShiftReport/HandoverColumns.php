<?php

namespace App\Filament\Pages\ShiftReport;

use Filament\Tables\Columns\TextColumn;

/** Columns shared by the shift report's handover table and the dashboard's handover monitor. */
class HandoverColumns
{
    public static function make(): array
    {
        return [
            TextColumn::make('asset_tag')->label('Phone')->weight('bold'),
            TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                'On time', 'Early' => 'success',
                'Late' => 'warning',
                'Missing' => 'danger',
                default => 'gray',
            }),
            TextColumn::make('at')->label('Handed over')->dateTime('H:i')->placeholder('-'),
            TextColumn::make('to')->label('To clerk')->placeholder('-'),
            TextColumn::make('by')->label('Recorded by')->placeholder('-'),
        ];
    }
}
