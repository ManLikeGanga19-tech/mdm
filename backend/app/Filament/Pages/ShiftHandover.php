<?php

namespace App\Filament\Pages;

use App\Mdm\Models\Clerk;
use App\Mdm\Models\CrewHandover;
use App\Mdm\Models\Device;
use App\Mdm\Permission;
use App\Mdm\Services\CrewHandoverService;
use App\Mdm\Services\HandoverEnforcement;
use App\Mdm\Services\HandoverService;
use App\Mdm\Services\RosterService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use RuntimeException;

/**
 * "My shift", the coordinator's start page: their crew and shift (from the roster), the crew-to-crew handover
 * (hand over when outgoing, accept when incoming), their clerks, the next crew, and the phones, each of which they
 * give to a clerk of their crew so usage stays attributed per clerk. Phone-sized: coordinators use it on a phone.
 */
class ShiftHandover extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.shift-handover';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'My shift';

    protected static ?string $navigationLabel = 'My shift';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission(Permission::HandoverRecord) ?? false;
    }

    /** A crew's coordinators see "My shift" first; anyone else (IT, managers) oversees every crew's "Shift handovers". */
    public static function getNavigationLabel(): string
    {
        return auth()->user()?->crew_id ? 'My shift' : 'Shift handovers';
    }

    public static function getNavigationSort(): ?int
    {
        return auth()->user()?->crew_id ? 0 : 3;
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    protected function getViewData(): array
    {
        $user = auth()->user()->load('crew.coordinators');
        $roster = app(RosterService::class);
        $crew = $user->crew;
        $slot = $crew ? $roster->slotFor($crew) : null;
        $context = app(CrewHandoverService::class)->context();

        return [
            'tz' => config('app.display_timezone'),
            'crew' => $crew,
            'partners' => $crew?->coordinators->reject(fn ($c) => $c->is($user))->pluck('name')->all() ?? [],
            'duty' => $slot,
            'next' => $slot ? $roster->next($slot['shift'], $slot['date']) : null,
            'context' => $context,
            'side' => $this->side($context),
            'status' => $context ? app(CrewHandoverService::class)->status($context['shift'], $context['date'], $context['handover']) : null,
            'clerks' => $crew ? $crew->clerks()->where('active', true)->with('currentAssignment.device')->orderBy('name')->get() : collect(),
        ];
    }

    /** Which side of the current handover this person is on: outgoing, incoming, admin (may act for both) or null. */
    private function side(?array $context): ?string
    {
        $user = auth()->user();
        if (! $context) {
            return null;
        }

        return match (true) {
            $user->crew_id && $user->crew_id === $context['outgoing']?->id => 'outgoing',
            $user->crew_id && $user->crew_id === $context['incoming']?->id => 'incoming',
            $user->isSuperAdmin() => 'admin',
            default => null,
        };
    }

    public function handOverPhonesAction(): Action
    {
        return Action::make('handOverPhones')
            ->label('Hand over phones')
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->size('lg')
            ->visible(function () {
                $context = app(CrewHandoverService::class)->context();

                return in_array($this->side($context), ['outgoing', 'admin'], true)
                    && $context['incoming'] && (! $context['handover'] || $context['handover']->isPending());
            })
            ->modalHeading(fn () => 'Hand over to '.(app(CrewHandoverService::class)->context()['incoming']?->labelWithCoordinators() ?? 'the next crew'))
            ->modalDescription('Untick a phone you are not handing over. Add a note for anything the next crew should know (damage, low battery, a phone kept back).')
            ->fillForm(fn () => ['items' => app(CrewHandoverService::class)->shiftPhones()->map(fn (Device $d) => [
                'device_id' => $d->id, 'asset_tag' => $d->asset_tag, 'include' => true, 'note' => null,
            ])->all()])
            ->schema([
                Repeater::make('items')
                    ->hiddenLabel()
                    ->addable(false)->deletable(false)->reorderable(false)
                    ->itemLabel(fn (array $state) => $state['asset_tag'] ?? 'Phone')
                    ->schema([
                        Hidden::make('device_id'),
                        Hidden::make('asset_tag'),
                        Toggle::make('include')->label('Handing over')->inline(),
                        TextInput::make('note')->hiddenLabel()->placeholder('Note (optional): e.g. cracked screen')->maxLength(255),
                    ])
                    ->columns(2),
                TextInput::make('note')->label('General note (optional)')->maxLength(255),
            ])
            ->action(function (array $data) {
                $context = app(CrewHandoverService::class)->context();
                $phones = collect($data['items'])->filter(fn ($row) => $row['include'])->mapWithKeys(fn ($row) => [(int) $row['device_id'] => $row['note'] ?? null])->all();
                $this->attempt(fn () => app(CrewHandoverService::class)->handOver(auth()->user(), $context['shift'], $context['date'], $phones, $data['note'] ?? null),
                    count($phones).' phone(s) handed over. The next crew is asked to accept them.');
            });
    }

    public function acceptPhonesAction(): Action
    {
        return Action::make('acceptPhones')
            ->label('Accept phones')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->size('lg')
            ->visible(function () {
                $context = app(CrewHandoverService::class)->context();

                return in_array($this->side($context), ['incoming', 'admin'], true) && $context['handover']?->isPending();
            })
            ->modalHeading(fn () => 'Accept phones from '.(app(CrewHandoverService::class)->context()['handover']?->fromCrew?->name ?? 'the previous crew'))
            ->modalDescription('Untick any phone you did not get, and say why. Phones you accept unlock straight away.')
            ->fillForm(fn () => ['items' => app(CrewHandoverService::class)->context()['handover']->items->map(fn ($item) => [
                'device_id' => $item->device_id, 'label' => $item->device->asset_tag.($item->note ? " · {$item->note}" : ''), 'received' => true, 'note' => null,
            ])->all()])
            ->schema([
                Repeater::make('items')
                    ->hiddenLabel()
                    ->addable(false)->deletable(false)->reorderable(false)
                    ->itemLabel(fn (array $state) => $state['label'] ?? 'Phone')
                    ->schema([
                        Hidden::make('device_id'),
                        Hidden::make('label'),
                        Toggle::make('received')->label('Received')->inline(),
                        TextInput::make('note')->hiddenLabel()->placeholder('If not received: why?')->maxLength(255),
                    ])
                    ->columns(2),
            ])
            ->action(function (array $data) {
                $handover = app(CrewHandoverService::class)->context()['handover'];
                $received = collect($data['items'])->mapWithKeys(fn ($row) => [(int) $row['device_id'] => ['received' => (bool) $row['received'], 'note' => $row['note'] ?? null]])->all();
                $this->attempt(fn () => app(CrewHandoverService::class)->accept(auth()->user(), $handover, $received),
                    'Phones accepted. Now give each one to a clerk below.');
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Phones')
            ->query(Device::query()->where('state', '!=', Device::STATE_RETIRED)->with('currentAssignment.clerk.crew'))
            ->defaultSort('asset_tag')
            ->paginated(false)
            ->poll('15s')
            ->columns([
                TextColumn::make('asset_tag')->label('Phone')->weight('bold')->searchable()
                    ->description(fn (Device $record) => app(HandoverEnforcement::class)->isLocked($record) ? 'Locked: waiting for handover' : null),
                TextColumn::make('currentAssignment.clerk.name')
                    ->label('Held by')
                    ->placeholder('Nobody')
                    ->description(fn (Device $record) => $record->currentAssignment?->clerk?->crew?->name),
                TextColumn::make('battery_percent')->label('Battery')->suffix('%')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('handOver')
                    ->label('Give to clerk')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->button()
                    ->modalHeading(fn (Device $record) => "Give {$record->asset_tag} to a clerk")
                    ->modalDescription(fn (Device $record) => $record->currentAssignment
                        ? "Currently held by {$record->currentAssignment->clerk->label()}."
                        : 'Nobody holds this phone now.')
                    ->schema([
                        Select::make('clerk_id')
                            ->label('Clerk taking the phone')
                            ->options(fn () => $this->clerkOptions())
                            ->searchable()
                            ->required(),
                        TextInput::make('note')
                            ->label('Note (optional)')
                            ->placeholder('e.g. screen cracked, battery 40%')
                            ->maxLength(255),
                    ])
                    ->action(function (array $data, Device $record) {
                        $clerk = Clerk::findOrFail($data['clerk_id']);
                        app(HandoverService::class)->handOver($record, $clerk, $data['note'] ?? null);
                        Notification::make()->title("{$record->asset_tag} is now with {$clerk->name}")->success()->send();
                    }),
                Action::make('return')
                    ->label('Returned')
                    ->icon(Heroicon::OutlinedInboxArrowDown)
                    ->color('gray')
                    ->visible(fn (Device $record) => $record->currentAssignment !== null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Device $record) => "{$record->asset_tag} returned")
                    ->modalDescription('Record that nobody holds this phone now (e.g. after the last shift).')
                    ->action(function (Device $record) {
                        app(HandoverService::class)->returnPhone($record);
                        Notification::make()->title("{$record->asset_tag} returned")->success()->send();
                    }),
            ]);
    }

    /** A coordinator picks from their own crew's clerks; others (no crew) from every active clerk. */
    private function clerkOptions(): array
    {
        $crewId = auth()->user()->crew_id;

        return Clerk::query()->where('active', true)
            ->when($crewId, fn ($q) => $q->where('crew_id', $crewId))
            ->with('crew')->orderBy('name')->get()
            ->mapWithKeys(fn (Clerk $c) => [$c->id => $c->label().($crewId || ! $c->crew ? '' : " · {$c->crew->name}")])
            ->all();
    }

    private function attempt(callable $change, string $success): void
    {
        try {
            $change();
            Notification::make()->title($success)->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    /** For the view: "Pending since 14:12 by Peter" etc. */
    public static function handoverLine(?CrewHandover $handover, string $tz): ?string
    {
        if (! $handover) {
            return null;
        }
        $line = 'Handed over by '.($handover->handedBy?->name ?? '?').' at '.$handover->handed_at->setTimezone($tz)->format('H:i')
            .' ('.$handover->items->count().' phones)';
        if ($handover->accepted_at) {
            $line .= ' · accepted by '.($handover->acceptedBy?->name ?? '?').' at '.$handover->accepted_at->setTimezone($tz)->format('H:i');
        }

        return $line;
    }
}
