<?php

namespace App\Filament\Resources\Shifts;

use App\Filament\Resources\Shifts\Pages\ManageShifts;
use App\Mdm\Models\Shift;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Shift times and their handover windows (and, from the page header, peak hours) for the shift report. */
class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static ?string $navigationLabel = 'Shifts & peak hours';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return auth()->user()?->canViewFleet() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false; // reports for past dates use the shift: turn it off instead
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(40)->placeholder('Shift A'),
            TimePicker::make('starts_at')->label('Starts (handover begins)')->seconds(false)->timezone('UTC')->required(), // wall-clock time: no time-zone conversion
            TimePicker::make('ends_at')->label('Ends (next shift\'s handover begins)')->seconds(false)->timezone('UTC')->required()
                ->helperText('A shift that ends earlier than it starts runs past midnight.'),
            TextInput::make('handover_minutes')->label('Handover window (minutes)')->numeric()->minValue(0)->maxValue(120)->default(30)->required(),
            Toggle::make('active')->default(true),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('starts_at')->label('Starts')->formatStateUsing(fn (string $state) => substr($state, 0, 5)),
                TextColumn::make('ends_at')->label('Ends')->formatStateUsing(fn (string $state) => substr($state, 0, 5)),
                TextColumn::make('handover_minutes')->label('Handover window')
                    ->formatStateUsing(fn (int $state, Shift $record) => substr($record->starts_at, 0, 5).' + '.$state.' min'),
                IconColumn::make('active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShifts::route('/'),
        ];
    }
}
