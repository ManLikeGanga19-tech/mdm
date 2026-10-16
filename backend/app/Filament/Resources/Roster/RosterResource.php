<?php

namespace App\Filament\Resources\Roster;

use App\Filament\Resources\Roster\Pages\ManageRoster;
use App\Mdm\Models\Crew;
use App\Mdm\Models\RosterEntry;
use App\Mdm\Permission;
use App\Mdm\Services\RosterService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** The roster: which crew works which shift each day. Generated from the weekly rotation; single days can be swapped. */
class RosterResource extends Resource
{
    protected static ?string $model = RosterEntry::class;

    protected static ?string $slug = 'roster';

    protected static ?string $modelLabel = 'roster day';

    protected static ?string $navigationLabel = 'Roster';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        // Coordinators read it too: they see when their crew works.
        return (auth()->user()?->hasPermission(Permission::RosterManage) || auth()->user()?->hasPermission(Permission::HandoverRecord)) ?? false;
    }

    public static function canCreate(): bool
    {
        return false; // filled by Generate rotation
    }

    public static function table(Table $table): Table
    {
        $tz = config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['shift', 'crew.coordinators'])->join('mdm_shifts', 'mdm_shifts.id', '=', 'mdm_roster.shift_id')
                ->select('mdm_roster.*')->orderBy('mdm_roster.date')->orderBy('mdm_shifts.starts_at'))
            ->defaultGroup(Group::make('date')->date()->collapsible()
                ->getTitleFromRecordUsing(fn (RosterEntry $record) => $record->date->format('l j F Y')))
            ->paginated([21, 63])
            ->columns([
                TextColumn::make('shift.name')->label('Shift')
                    ->description(fn (RosterEntry $record) => substr($record->shift->starts_at, 0, 5).'–'.substr($record->shift->ends_at, 0, 5)),
                TextColumn::make('crew.name')->label('Crew')->badge()
                    ->color(fn (RosterEntry $record) => ['primary', 'success', 'warning', 'info'][$record->crew_id % 4]),
                TextColumn::make('crew.coordinators.name')->label('Coordinators')->listWithLineBreaks()->placeholder('-'),
            ])
            ->filters([
                Filter::make('upcoming')->label('From today')->default()
                    ->query(fn (Builder $query) => $query->whereDate('mdm_roster.date', '>=', Carbon::now($tz)->subDay()->toDateString())),
            ])
            ->recordActions([
                Action::make('swap')
                    ->label('Change crew')
                    ->icon(Heroicon::OutlinedArrowsUpDown)
                    ->visible(fn () => auth()->user()->hasPermission(Permission::RosterManage))
                    ->fillForm(fn (RosterEntry $record) => ['crew_id' => $record->crew_id])
                    ->schema([Select::make('crew_id')->label('Crew')->options(fn () => Crew::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))->required()])
                    ->action(function (RosterEntry $record, array $data) {
                        app(RosterService::class)->set($record->shift, $record->date->toDateString(), Crew::findOrFail($data['crew_id']));
                        Notification::make()->title('Roster changed')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No roster yet')
            ->emptyStateDescription('Create the crews, then use Generate rotation.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRoster::route('/'),
        ];
    }
}
