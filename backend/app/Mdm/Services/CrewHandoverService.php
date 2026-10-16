<?php

namespace App\Mdm\Services;

use App\Mdm\Models\AuditLog;
use App\Mdm\Models\Crew;
use App\Mdm\Models\CrewHandover;
use App\Mdm\Models\CrewHandoverItem;
use App\Mdm\Models\Device;
use App\Mdm\Models\Shift;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Coordinator-to-coordinator handover at each shift change. The outgoing crew's coordinator hands the shift phones
 * over (with a note per phone); the incoming crew's coordinator accepts, ticking what actually arrived. Accepted phones
 * leave the handover lock straight away (HandoverEnforcement + a wake-up); missing ones mark the handover disputed.
 * The incoming coordinator then gives each phone to a clerk of their crew (HandoverService), as before.
 */
class CrewHandoverService
{
    public function __construct(private RosterService $roster) {}

    /**
     * The handover that matters now: the next shift's, from 30 minutes before it starts (early handovers), otherwise
     * the one that opened the shift running now (late handovers stay possible until the shift ends).
     *
     * @return array{shift: Shift, date: string, start: Carbon, window_end: Carbon, shift_end: Carbon, incoming: ?Crew, outgoing: ?Crew, handover: ?CrewHandover}|null
     */
    public function context(?Carbon $now = null): ?array
    {
        $now ??= now();
        $tz = config('app.display_timezone');
        $best = null;
        foreach ($this->roster->shifts() as $shift) {
            foreach ([-1, 0, 1] as $offset) {
                $date = $now->copy()->setTimezone($tz)->addDays($offset)->toDateString();
                [$start, $end] = $shift->window($date);
                if ($now->gte($start->copy()->subMinutes(ShiftReport::EARLY_GRACE_MINUTES)) && $now->lt($end)
                    && (! $best || $start->gt($best['start']))) {
                    $best = ['shift' => $shift, 'date' => $date, 'start' => $start, 'shift_end' => $end];
                }
            }
        }
        if (! $best) {
            return null;
        }

        return $best + [
            'window_end' => $best['shift']->handoverWindow($best['date'])[1],
            'incoming' => $this->roster->crewFor($best['shift'], $best['date']),
            'outgoing' => $this->roster->previous($best['shift'], $best['date'])['crew'] ?? null,
            'handover' => CrewHandover::query()->with(['items.device', 'handedBy', 'acceptedBy'])
                ->where('shift_id', $best['shift']->id)->whereDate('date', $best['date'])->first(),
        ];
    }

    /** @return Collection<int, Device> the shared shift phones (groups with the handover lock), by asset tag */
    public function shiftPhones(): Collection
    {
        return Device::query()
            ->where('state', Device::STATE_ENROLLED)
            ->with(['group.policy', 'currentAssignment.clerk'])
            ->orderBy('asset_tag')
            ->get()
            ->filter(fn (Device $d) => (bool) ($d->group?->policy?->payload['handover_lock'] ?? false))
            ->values();
    }

    /**
     * The outgoing crew hands the phones over. A pending handover can be redone (e.g. a phone was forgotten); an
     * accepted one can't.
     *
     * @param  array<int, ?string>  $phones  device id => note
     */
    public function handOver(User $by, Shift $shift, string $date, array $phones, ?string $note = null): CrewHandover
    {
        if ($phones === []) {
            throw new RuntimeException('Tick at least one phone to hand over.');
        }
        $incoming = $this->roster->crewFor($shift, $date) ?? throw new RuntimeException("No crew is on the roster for {$shift->name} on {$date}.");
        $outgoing = $this->roster->previous($shift, $date)['crew'] ?? null;
        if (! $by->isSuperAdmin() && (! $outgoing || $by->crew_id !== $outgoing->id)) {
            throw new RuntimeException('Only a coordinator of the outgoing crew hands the phones over.');
        }

        $handover = DB::transaction(function () use ($by, $shift, $date, $phones, $note, $incoming, $outgoing) {
            $handover = CrewHandover::query()->where('shift_id', $shift->id)->whereDate('date', $date)->lockForUpdate()->first();
            if ($handover && ! $handover->isPending()) {
                throw new RuntimeException('This handover was already accepted.');
            }
            $handover ??= new CrewHandover(['shift_id' => $shift->id, 'date' => $date]);
            $handover->fill([
                'from_crew_id' => $outgoing?->id, 'to_crew_id' => $incoming->id, 'handed_by' => $by->id,
                'handed_at' => now(), 'status' => CrewHandover::PENDING, 'note' => $note,
            ])->save();
            $handover->items()->delete();
            foreach ($phones as $deviceId => $phoneNote) {
                $handover->items()->create(['device_id' => $deviceId, 'note' => $phoneNote ?: null]);
            }

            return $handover;
        });

        AuditLog::record('crew_handover.handed', $handover, [
            'shift' => $shift->name, 'date' => $date, 'from' => $outgoing?->name, 'to' => $incoming->name, 'phones' => count($phones),
        ]);
        $this->notify($incoming, 'Phones handed over to your crew', ($outgoing?->name ?? 'The previous crew').' handed you '.count($phones).' phone(s) for '.$shift->name.'. Accept them on My shift.');

        return $handover;
    }

    /**
     * The incoming crew accepts: each phone received or not (with a note). Received phones are released from the
     * handover lock at once.
     *
     * @param  array<int, array{received: bool, note?: ?string}>  $received  device id => outcome
     */
    public function accept(User $by, CrewHandover $handover, array $received): CrewHandover
    {
        if (! $handover->isPending()) {
            throw new RuntimeException('This handover was already accepted.');
        }
        if (! $by->isSuperAdmin() && $by->crew_id !== $handover->to_crew_id) {
            throw new RuntimeException('Only a coordinator of the incoming crew accepts the phones.');
        }

        DB::transaction(function () use ($by, $handover, $received) {
            foreach ($handover->items as $item) {
                $outcome = $received[$item->device_id] ?? ['received' => false];
                $item->update(['received' => (bool) $outcome['received'], 'receive_note' => ($outcome['note'] ?? null) ?: null]);
            }
            $missing = $handover->items->contains(fn (CrewHandoverItem $i) => ! $i->received);
            $handover->update([
                'accepted_by' => $by->id, 'accepted_at' => now(),
                'status' => $missing ? CrewHandover::DISPUTED : CrewHandover::ACCEPTED,
            ]);
        });

        $handover->load('items.device', 'fromCrew', 'toCrew', 'shift');
        $missing = $handover->items->where('received', false)->map(fn ($i) => $i->device->asset_tag)->values();
        AuditLog::record('crew_handover.accepted', $handover, [
            'shift' => $handover->shift->name, 'date' => $handover->date->toDateString(), 'status' => $handover->status,
            'missing' => $missing->all() ?: null,
        ]);
        // The lock lifts within seconds on every phone received.
        app(PushService::class)->wakeMany($handover->items->where('received', true)->pluck('device'));
        if ($missing->isNotEmpty() && $handover->fromCrew) {
            $this->notify($handover->fromCrew, 'Handover disputed', "{$handover->toCrew->name} did not receive: ".$missing->implode(', ').'.');
        }

        return $handover;
    }

    /**
     * How a handover went, for the dashboard: on time, late (handed or accepted after the window), pending, disputed,
     * or missing (nothing handed over by the end of the window).
     */
    public function status(Shift $shift, string $date, ?CrewHandover $handover, ?Carbon $now = null): string
    {
        $now ??= now();
        [, $windowEnd] = $shift->handoverWindow($date);
        if (! $handover) {
            return $now->gte($windowEnd) ? 'missing' : 'waiting';
        }
        if ($handover->isPending()) {
            return $now->gte($windowEnd) ? 'late' : 'pending';
        }
        if ($handover->status === CrewHandover::DISPUTED) {
            return 'disputed';
        }

        return $handover->accepted_at->lte($windowEnd) ? 'on time' : 'late';
    }

    private function notify(Crew $crew, string $title, string $body): void
    {
        foreach ($crew->coordinators as $coordinator) {
            Notification::make()->title($title)->body($body)->icon('heroicon-o-arrows-right-left')->sendToDatabase($coordinator);
        }
    }
}
