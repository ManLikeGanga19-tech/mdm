<?php

namespace App\Filament\Resources\Clerks;

use App\Filament\Resources\Clerks\Pages\ManageClerks;
use App\Mdm\Models\Clerk;
use App\Mdm\Permission;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ClerkResource extends Resource
{
    protected static ?string $model = Clerk::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->check(); // coordinators look clerks up too
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasPermission(Permission::ClerksManage) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->hasPermission(Permission::ClerksManage) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false; // clerks who left are marked inactive so past handovers keep their name
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('staff_number')->label('Staff number')->required()->maxLength(40)->unique(ignoreRecord: true),
            TextInput::make('name')->required()->maxLength(120),
            Toggle::make('active')->default(true)->helperText('Inactive clerks are hidden from the handover list.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('staff_number')->label('Staff number')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('currentAssignment.device.asset_tag')->label('Holding')->placeholder('-'),
                IconColumn::make('active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('active'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageClerks::route('/'),
        ];
    }
}
