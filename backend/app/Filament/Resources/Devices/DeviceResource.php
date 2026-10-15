<?php

namespace App\Filament\Resources\Devices;

use App\Filament\Resources\Devices\Pages\CreateDevice;
use App\Filament\Resources\Devices\Pages\EditDevice;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Resources\Devices\Pages\ViewDevice;
use App\Filament\Resources\Devices\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\Devices\RelationManagers\CommandsRelationManager;
use App\Mdm\Models\Device;
use App\Mdm\Services\PolicyBuilder;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static ?string $modelLabel = 'phone';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'asset_tag';

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
        return false; // phones are retired, never deleted, so their history stays auditable
    }

    /** Pre-registering a phone: the rest of its details arrive from the phone at enrollment. */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('asset_tag')
                ->label('Asset tag')
                ->helperText('The label on the phone, e.g. NP-0042.')
                ->required()
                ->maxLength(40)
                ->unique(ignoreRecord: true),
            Select::make('group_id')
                ->label('Group')
                ->relationship('group', 'name')
                ->helperText('The phone follows this group\'s rules.'),
            Select::make('state')
                ->options([
                    Device::STATE_PENDING => 'Waiting for enrollment',
                    Device::STATE_ENROLLED => 'Enrolled',
                    Device::STATE_RETIRED => 'Retired',
                ])
                ->default(Device::STATE_PENDING)
                ->required(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Who has it')
                ->schema([
                    TextEntry::make('currentAssignment.clerk.name')
                        ->label('Held by')
                        ->placeholder('Nobody (returned)')
                        ->weight('bold'),
                    TextEntry::make('currentAssignment.clerk.staff_number')->label('Staff number')->placeholder('-'),
                    TextEntry::make('currentAssignment.started_at')->label('Since')->dateTime()->since()->placeholder('-'),
                ])
                ->columns(3),
            Section::make('Rules')
                ->schema([
                    TextEntry::make('group.name')->label('Group')->placeholder('No group'),
                    TextEntry::make('hotspot')
                        ->label('Hotspot')
                        ->state(fn (Device $record) => app(PolicyBuilder::class)->build($record)['hotspot'])
                        ->formatStateUsing(fn (string $state) => $state === 'blocked' ? 'Blocked' : 'Allowed, monitored')
                        ->badge()
                        ->color(fn (string $state) => $state === 'blocked' ? 'gray' : 'warning')
                        ->helperText(fn (Device $record) => isset($record->policy_overrides['hotspot'])
                            ? 'Set for this phone only' : 'Following the group rule'),
                    TextEntry::make('applied_policy_version')
                        ->label('Rules applied on phone')
                        ->state(fn (Device $record) => $record->applied_policy_version === app(PolicyBuilder::class)->build($record)['version'])
                        ->formatStateUsing(fn (bool $state) => $state ? 'Up to date' : 'Waiting for the phone')
                        ->badge()
                        ->color(fn (bool $state) => $state ? 'success' : 'warning'),
                ])
                ->columns(3),
            Section::make('Phone')
                ->schema([
                    TextEntry::make('state')->badge()
                        ->formatStateUsing(fn (string $state) => match ($state) {
                            Device::STATE_PENDING => 'Waiting for enrollment',
                            Device::STATE_ENROLLED => 'Enrolled',
                            default => 'Retired',
                        })
                        ->color(fn (string $state) => $state === Device::STATE_ENROLLED ? 'success' : 'gray'),
                    TextEntry::make('last_checkin_at')->label('Last check-in')->since()->placeholder('Never'),
                    TextEntry::make('battery_percent')->label('Battery')->placeholder('-')
                        ->formatStateUsing(fn (Device $record) => self::battery($record))
                        ->color(fn (Device $record) => self::batteryColor($record)),
                    TextEntry::make('network_type')->label('Network')->placeholder('-')
                        ->formatStateUsing(fn (string $state) => match ($state) {
                            'wifi' => 'Wi-Fi', 'mobile' => 'Mobile data', 'none' => 'Offline', default => ucfirst($state),
                        }),
                    TextEntry::make('model')->placeholder('-')
                        ->formatStateUsing(fn (Device $record) => trim("{$record->manufacturer} {$record->model}")),
                    TextEntry::make('android_version')->label('Android')->placeholder('-'),
                    TextEntry::make('security_patch')->label('Security patch')->placeholder('-'),
                    TextEntry::make('serial')->placeholder('-')->copyable(),
                    TextEntry::make('agent_version_name')->label('Agent version')->placeholder('-'),
                    TextEntry::make('enrolled_at')->label('Enrolled')->dateTime()->placeholder('-'),
                    TextEntry::make('notice_acknowledged_at')->label('Staff notice acknowledged')->dateTime()->placeholder('Not yet'),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('asset_tag')
            ->defaultSort('asset_tag')
            ->columns([
                TextColumn::make('asset_tag')->label('Asset tag')->searchable()->sortable()->weight('bold'),
                TextColumn::make('currentAssignment.clerk.name')
                    ->label('Held by')
                    ->placeholder('Nobody')
                    ->description(fn (Device $record) => $record->currentAssignment?->started_at?->diffForHumans()),
                TextColumn::make('group.name')->label('Group')->placeholder('-')->toggleable(),
                TextColumn::make('state')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Device::STATE_PENDING => 'Waiting',
                        Device::STATE_ENROLLED => 'Enrolled',
                        default => 'Retired',
                    })
                    ->color(fn (string $state) => $state === Device::STATE_ENROLLED ? 'success' : 'gray'),
                TextColumn::make('last_checkin_at')->label('Last check-in')->since()->sortable()->placeholder('Never'),
                TextColumn::make('battery_percent')->label('Battery')->sortable()->placeholder('-')
                    ->formatStateUsing(fn (Device $record) => self::battery($record))
                    ->color(fn (Device $record) => self::batteryColor($record)),
                TextColumn::make('model')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('agent_version_name')->label('Agent')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('group')->relationship('group', 'name'),
                SelectFilter::make('state')->options([
                    Device::STATE_PENDING => 'Waiting for enrollment',
                    Device::STATE_ENROLLED => 'Enrolled',
                    Device::STATE_RETIRED => 'Retired',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /** "64%" or "64% · charging", as reported at the last check-in. */
    public static function battery(Device $device): string
    {
        return $device->battery_percent === null ? '-'
            : $device->battery_percent.'%'.($device->battery_charging ? ' · charging' : '');
    }

    public static function batteryColor(Device $device): ?string
    {
        return match (true) {
            $device->battery_percent === null, (bool) $device->battery_charging => null,
            $device->battery_percent <= 15 => 'danger',
            $device->battery_percent <= 30 => 'warning',
            default => null,
        };
    }

    public static function getRelations(): array
    {
        return [
            AssignmentsRelationManager::class,
            CommandsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDevices::route('/'),
            'create' => CreateDevice::route('/create'),
            'view' => ViewDevice::route('/{record}'),
            'edit' => EditDevice::route('/{record}/edit'),
        ];
    }
}
