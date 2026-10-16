<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Mdm\Models\AdminRole;
use App\Mdm\Models\AuditLog;
use App\Mdm\Permission;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Roles & permissions: which role may do what (App\Mdm\Permission). The super admin role always has everything and
 * can't be edited; built-in roles can be edited but not deleted; custom roles can be deleted once nobody has them.
 */
class AdminRoleResource extends Resource
{
    protected static ?string $model = AdminRole::class;

    protected static ?string $modelLabel = 'role';

    protected static ?string $slug = 'roles';

    protected static ?string $navigationLabel = 'Roles & permissions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermission(Permission::RolesManage) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny() && ! $record->isSuperAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canViewAny() && $record->key === null && ! $record->users()->exists();
    }

    public static function form(Schema $schema): Schema
    {
        $sections = collect(Permission::cases())->groupBy(fn (Permission $p) => $p->group())->map(
            fn ($permissions, string $group) => Section::make($group)->schema([
                CheckboxList::make('permissions_'.str($group)->snake())
                    ->hiddenLabel()
                    ->options($permissions->mapWithKeys(fn (Permission $p) => [$p->value => $p->label()])->all())
                    ->descriptions($permissions->filter->isSensitive()->mapWithKeys(fn (Permission $p) => [$p->value => 'Sensitive: give sparingly'])->all())
                    ->bulkToggleable(),
            ])->compact()->columnSpan(1),
        )->values()->all();

        return $schema->components([
            TextInput::make('name')->required()->maxLength(60)->unique(ignoreRecord: true),
            TextInput::make('description')->maxLength(255),
            ...$sections,
        ])->columns(2);
    }

    /** The grid's checkbox fields for a role (one list per permission group). */
    public static function fillPermissions(AdminRole $role): array
    {
        $values = $role->permissionValues();
        $data = ['name' => $role->name, 'description' => $role->description];
        foreach (collect(Permission::cases())->groupBy(fn (Permission $p) => $p->group()) as $group => $permissions) {
            $data['permissions_'.str($group)->snake()] = $permissions->map->value->intersect($values)->values()->all();
        }

        return $data;
    }

    /** Saves name, description and the ticked permissions; audits what changed. */
    public static function save(AdminRole $role, array $data): AdminRole
    {
        $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null])->save();
        $ticked = collect($data)->filter(fn ($v, $k) => str_starts_with($k, 'permissions_'))->flatten()->all();
        $diff = $role->syncPermissions($ticked);
        AuditLog::record($role->wasRecentlyCreated ? 'role.created' : 'role.updated', $role, ['role' => $role->name] + $diff);

        return $role;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('description')->wrap()->color('gray'),
                TextColumn::make('permissions')
                    ->state(fn (AdminRole $record) => $record->isSuperAdmin() ? 'All' : count($record->permissionValues()).' of '.count(Permission::cases()))
                    ->badge(),
                TextColumn::make('users_count')->counts('users')->label('Accounts'),
            ])
            ->recordActions([
                EditAction::make()
                    ->fillForm(fn (AdminRole $record) => self::fillPermissions($record))
                    ->using(fn (AdminRole $record, array $data) => self::save($record, $data)),
                DeleteAction::make()
                    ->after(fn (AdminRole $record) => AuditLog::record('role.deleted', null, ['role' => $record->name])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRoles::route('/'),
        ];
    }
}
