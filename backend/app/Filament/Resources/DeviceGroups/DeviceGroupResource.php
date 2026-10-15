<?php

namespace App\Filament\Resources\DeviceGroups;

use App\Filament\Resources\DeviceGroups\Pages\ManageDeviceGroups;
use App\Mdm\Models\DeviceGroup;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Groups of phones and their rules. Each group edits its own policy (mdm_policies) in place. */
class DeviceGroupResource extends Resource
{
    protected static ?string $model = DeviceGroup::class;

    protected static ?string $modelLabel = 'group';

    protected static ?string $navigationLabel = 'Groups & rules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

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
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80)->unique(ignoreRecord: true),
            Section::make('Rules')
                ->relationship('policy')
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, callable $get) => $data + ['name' => $get('name') ?: 'Group policy'])
                ->schema([
                    Radio::make('payload.hotspot')
                        ->label('Hotspot')
                        ->options(['blocked' => 'Blocked', 'allowed' => 'Allowed, monitored'])
                        ->default('blocked')
                        ->required(),
                    TagsInput::make('payload.blocked_apps')
                        ->label('Blocked apps')
                        ->placeholder('Add a package name, e.g. com.zhiliaoapp.musically')
                        ->helperText('Android package names. Blocked apps are greyed out on the phone.'),
                ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('policy.payload.hotspot')->label('Hotspot')->badge()
                    ->formatStateUsing(fn (?string $state) => $state === 'allowed' ? 'Allowed, monitored' : 'Blocked')
                    ->color(fn (?string $state) => $state === 'allowed' ? 'warning' : 'gray'),
                TextColumn::make('policy.payload.blocked_apps')->label('Blocked apps')->badge()->placeholder('None'),
                TextColumn::make('devices_count')->counts('devices')->label('Phones'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDeviceGroups::route('/'),
        ];
    }
}
