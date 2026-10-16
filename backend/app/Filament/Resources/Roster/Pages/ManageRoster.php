<?php

namespace App\Filament\Resources\Roster\Pages;

use App\Filament\Resources\Roster\RosterResource;
use App\Mdm\Models\Crew;
use App\Mdm\Models\Shift;
use App\Mdm\Permission;
use App\Mdm\Services\RosterService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ManageRoster extends ManageRecords
{
    protected static string $resource = RosterResource::class;

    public function getSubheading(): ?string
    {
        return 'Each week every crew moves on one shift (mornings → afternoons → nights → mornings). Change single days for swaps.';
    }

    protected function getHeaderActions(): array
    {
        $tz = config('app.display_timezone');

        return [
            Action::make('generate')
                ->label('Generate rotation')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn () => auth()->user()->hasPermission(Permission::RosterManage))
                ->modalDescription('Fills the roster from the start date. Days already in that range are replaced; days before it are kept.')
                ->schema(fn () => [
                    DatePicker::make('from')->label('Start date (first day of a rotation week)')->required()->native(false)
                        ->default(Carbon::now($tz)->next(Carbon::MONDAY)->toDateString()),
                    TextInput::make('weeks')->numeric()->minValue(1)->maxValue(26)->default(8)->required(),
                    ...app(RosterService::class)->shifts()->map(fn (Shift $shift) => Select::make("crew_{$shift->id}")
                        ->label("Crew on {$shift->label()} in the first week")
                        ->options(fn () => Crew::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                        ->required())->all(),
                ])
                ->action(function (array $data) {
                    $crews = collect($data)->filter(fn ($v, $k) => str_starts_with($k, 'crew_'))
                        ->mapWithKeys(fn ($crewId, $key) => [(int) substr($key, 5) => (int) $crewId])->all();
                    try {
                        $count = app(RosterService::class)->generate(Carbon::parse($data['from']), (int) $data['weeks'], $crews);
                        Notification::make()->title("Roster generated: {$count} shifts")->success()->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
