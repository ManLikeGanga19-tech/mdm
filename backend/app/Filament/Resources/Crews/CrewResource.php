<?php

namespace App\Filament\Resources\Crews;

use App\Filament\Resources\Crews\Pages\ManageCrews;
use App\Mdm\Models\AuditLog;
use App\Mdm\Models\Crew;
use App\Mdm\Models\RosterEntry;
use App\Mdm\Permission;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Crews: two coordinators (admin-site accounts) and their clerks (set on Clerks or by CSV), rotating on the roster. */
class CrewResource extends Resource
{
    protected static ?string $model = Crew::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermission(Permission::RosterManage) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return self::canViewAny() && ! $record->clerks()->exists() && ! RosterEntry::where('crew_id', $record->id)->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(60)->unique(ignoreRecord: true)->placeholder('e.g. Crew 1'),
            Select::make('coordinator_ids')
                ->label('Coordinators')
                ->multiple()
                ->maxItems(Crew::MAX_COORDINATORS)
                ->options(fn () => User::query()->whereNull('disabled_at')->orderBy('name')->get()
                    ->filter(fn (User $u) => $u->hasPermission(Permission::HandoverRecord))
                    ->mapWithKeys(fn (User $u) => [$u->id => $u->name.($u->crew ? " (now {$u->crew->name})" : '')]))
                ->helperText('Two admin-site accounts with a role that records handovers. Choosing someone moves them from their old crew.'),
            Toggle::make('active')->default(true),
        ]);
    }

    /** Saves the crew and moves the chosen coordinators onto it; audited. */
    public static function save(Crew $crew, array $data): Crew
    {
        DB::transaction(function () use ($crew, $data) {
            $crew->fill(['name' => $data['name'], 'active' => $data['active'] ?? true])->save();
            $ids = array_map('intval', $data['coordinator_ids'] ?? []);
            User::query()->where('crew_id', $crew->id)->whereNotIn('id', $ids)->update(['crew_id' => null]);
            User::query()->whereIn('id', $ids)->update(['crew_id' => $crew->id]);
        });
        AuditLog::record($crew->wasRecentlyCreated ? 'crew.created' : 'crew.updated', $crew, [
            'crew' => $crew->name, 'coordinators' => $crew->coordinators()->pluck('name')->all(),
        ]);

        return $crew;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('coordinators.name')->label('Coordinators')->listWithLineBreaks()->placeholder('None yet'),
                TextColumn::make('clerks_count')->counts('clerks')->label('Clerks'),
                IconColumn::make('active')->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->fillForm(fn (Crew $record) => ['name' => $record->name, 'active' => $record->active, 'coordinator_ids' => $record->coordinators()->pluck('id')->all()])
                    ->using(fn (Crew $record, array $data) => self::save($record, $data)),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCrews::route('/'),
        ];
    }
}
