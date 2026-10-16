<?php

namespace App\Filament\Pages;

use App\Filament\Pages\ShiftReport\ShiftHandoverTable;
use App\Filament\Pages\ShiftReport\ShiftSummary;
use App\Filament\Pages\ShiftReport\ShiftUsageTable;
use App\Mdm\Models\Shift;
use App\Mdm\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * Shift report: one shift on one date (?date=YYYY-MM-DD&shift=<id>, bookmarkable). Defaults to the shift running now.
 */
class ShiftReportPage extends Page
{
    protected string $view = 'filament.pages.shift-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'Shift report';

    protected static ?string $slug = 'shift-report';

    protected static ?int $navigationSort = 2;

    #[Url]
    public ?string $date = null;

    #[Url]
    public ?int $shift = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission(Permission::ReportsView) ?? false;
    }

    public function mount(): void
    {
        if (! $this->date || ! $this->shift || ! Shift::find($this->shift)) {
            [$current, $date] = Shift::at(now()) ?? [Shift::query()->orderBy('starts_at')->first(), now(config('app.display_timezone'))->toDateString()];
            $this->shift = $current?->id;
            $this->date = $date;
        }
    }

    public function getTitle(): string
    {
        $shift = Shift::find($this->shift);

        return $shift ? "{$shift->name}, ".Carbon::parse($this->date)->format('D j M Y') : 'Shift report';
    }

    public function getSubheading(): ?string
    {
        $shift = Shift::find($this->shift);
        if (! $shift) {
            return 'No shifts are set up yet: add them under Shifts & peak hours.';
        }
        [$start, $end] = $shift->window($this->date);
        $tz = config('app.display_timezone');

        return sprintf('%s – %s (handover %s–%s). Data per site is an estimate; usage is recorded per hour.',
            $start->copy()->setTimezone($tz)->format('D H:i'), $end->copy()->setTimezone($tz)->format('D H:i'),
            $start->copy()->setTimezone($tz)->format('H:i'), $start->copy()->addMinutes($shift->handover_minutes)->setTimezone($tz)->format('H:i'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previous')->label('Previous shift')->icon(Heroicon::OutlinedChevronLeft)->color('gray')
                ->url(fn () => $this->urlFor(...$this->step(-1))),
            Action::make('pick')->label('Choose shift')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                ->fillForm(fn () => ['date' => $this->date, 'shift' => $this->shift])
                ->schema([
                    DatePicker::make('date')->required()->maxDate(now(config('app.display_timezone'))->toDateString()),
                    Select::make('shift')->options(fn () => Shift::query()->orderBy('starts_at')->get()->mapWithKeys(fn (Shift $s) => [$s->id => $s->label()]))->required(),
                ])
                ->action(fn (array $data) => $this->redirect($this->urlFor((int) $data['shift'], $data['date']))),
            Action::make('next')->label('Next shift')->icon(Heroicon::OutlinedChevronRight)->iconPosition('after')->color('gray')
                ->url(fn () => $this->urlFor(...$this->step(1))),
        ];
    }

    /** @return array{0: int, 1: string} the shift before/after the current one, crossing midnight as needed */
    private function step(int $direction): array
    {
        $shifts = Shift::query()->where('active', true)->orderBy('starts_at')->get()->values();
        $index = $shifts->search(fn (Shift $s) => $s->id === $this->shift);
        if ($index === false || $shifts->isEmpty()) {
            return [$this->shift, $this->date];
        }
        $next = $index + $direction;
        $date = Carbon::parse($this->date);
        if ($next >= $shifts->count()) {
            [$next, $date] = [0, $date->addDay()];
        } elseif ($next < 0) {
            [$next, $date] = [$shifts->count() - 1, $date->subDay()];
        }

        return [$shifts[$next]->id, $date->toDateString()];
    }

    private function urlFor(int $shift, string $date): string
    {
        return static::getUrl(['date' => $date, 'shift' => $shift]);
    }

    protected function getHeaderWidgets(): array
    {
        return [ShiftSummary::class, ShiftHandoverTable::class, ShiftUsageTable::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getWidgetData(): array
    {
        return ['shiftId' => $this->shift, 'date' => $this->date];
    }
}
