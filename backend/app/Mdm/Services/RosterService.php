<?php

namespace App\Mdm\Services;

use App\Mdm\Models\AuditLog;
use App\Mdm\Models\Crew;
use App\Mdm\Models\RosterEntry;
use App\Mdm\Models\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The roster: which crew works which shift on which date. Generated from a weekly rotation (each week every crew
 * moves on to the next shift: mornings -> afternoons -> nights -> mornings) and editable day by day for swaps. A
 * shift's date is the day it starts, in the admin's time zone (a night shift belongs to the evening it starts).
 */
class RosterService
{
    /** @return Collection<int, Shift> active shifts in day order (A, B, C) */
    public function shifts(): Collection
    {
        return Shift::query()->where('active', true)->orderBy('starts_at')->get();
    }

    /**
     * Fills $weeks weeks from $from. $crewIds lists, per shift in day order, the crew working it in the first week;
     * each following week every crew moves to the next shift. Existing entries in the range are replaced.
     *
     * @param  array<int, int>  $crewIds  shift id => crew id for the first week
     */
    public function generate(Carbon $from, int $weeks, array $crewIds): int
    {
        $shifts = $this->shifts()->values();
        $order = $shifts->map(fn (Shift $s) => $crewIds[$s->id] ?? null)->all();
        if (in_array(null, $order, true) || count(array_unique($order)) !== count($order)) {
            throw new InvalidArgumentException('Pick a different crew for each shift.');
        }
        $n = count($order);
        $from = $from->copy()->startOfDay();
        $days = $weeks * 7;

        DB::transaction(function () use ($shifts, $order, $n, $from, $days) {
            RosterEntry::query()
                ->whereBetween('date', [$from->toDateString(), $from->copy()->addDays($days - 1)->toDateString()])
                ->delete();
            $rows = [];
            for ($d = 0; $d < $days; $d++) {
                $week = intdiv($d, 7);
                foreach ($shifts as $i => $shift) {
                    // Week w: shift i is worked by the crew that worked shift i-w in week 0.
                    $rows[] = [
                        'date' => $from->copy()->addDays($d)->toDateString(),
                        'shift_id' => $shift->id,
                        'crew_id' => $order[(($i - $week) % $n + $n) % $n],
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
            RosterEntry::insert($rows);
        });
        AuditLog::record('roster.generated', null, ['from' => $from->toDateString(), 'weeks' => $days / 7]);

        return $days * $n;
    }

    /** Puts another crew on one shift (a swap). */
    public function set(Shift $shift, string $date, Crew $crew): RosterEntry
    {
        $entry = RosterEntry::query()->firstOrNew(['date' => $date, 'shift_id' => $shift->id]);
        $from = $entry->crew?->name;
        $entry->crew()->associate($crew)->save();
        AuditLog::record('roster.changed', null, ['date' => $date, 'shift' => $shift->name, 'from' => $from, 'to' => $crew->name]);

        return $entry;
    }

    public function crewFor(Shift $shift, string $date): ?Crew
    {
        return RosterEntry::query()->where('shift_id', $shift->id)->whereDate('date', $date)->first()?->crew;
    }

    /**
     * The shift running just before $shift starts on $date, and its crew: the crew handing the phones over.
     *
     * @return array{shift: Shift, date: string, crew: ?Crew}|null
     */
    public function previous(Shift $shift, string $date): ?array
    {
        [$start] = $shift->window($date);
        [$prev, $prevDate] = Shift::at($start->copy()->subMinute()) ?? [null, null];

        return $prev ? ['shift' => $prev, 'date' => $prevDate, 'crew' => $this->crewFor($prev, $prevDate)] : null;
    }

    /**
     * The shift running right after $shift on $date ends, and its crew: the crew to hand over to.
     *
     * @return array{shift: Shift, date: string, crew: ?Crew}|null
     */
    public function next(Shift $shift, string $date): ?array
    {
        [, $end] = $shift->window($date);
        [$next, $nextDate] = Shift::at($end->copy()->addMinute()) ?? [null, null];

        return $next ? ['shift' => $next, 'date' => $nextDate, 'crew' => $this->crewFor($next, $nextDate)] : null;
    }

    /**
     * The crew's shift running now, or its next one within two weeks.
     *
     * @return array{shift: Shift, date: string, start: Carbon, end: Carbon, on_duty: bool}|null
     */
    public function slotFor(Crew $crew, ?Carbon $at = null): ?array
    {
        $at ??= now();
        $today = $at->copy()->setTimezone(config('app.display_timezone'))->startOfDay();
        $entries = RosterEntry::query()->with('shift')->where('crew_id', $crew->id)
            ->whereBetween('date', [$today->copy()->subDay()->toDateString(), $today->copy()->addDays(14)->toDateString()])
            ->get()
            ->filter(fn (RosterEntry $e) => $e->shift?->active)
            ->map(function (RosterEntry $e) {
                $date = $e->date->toDateString();
                [$start, $end] = $e->shift->window($date);

                return ['shift' => $e->shift, 'date' => $date, 'start' => $start, 'end' => $end];
            })
            ->sortBy('start');

        $current = $entries->first(fn ($s) => $at->gte($s['start']) && $at->lt($s['end']));
        if ($current) {
            return $current + ['on_duty' => true];
        }
        $next = $entries->first(fn ($s) => $s['start']->gt($at));

        return $next ? $next + ['on_duty' => false] : null;
    }
}
