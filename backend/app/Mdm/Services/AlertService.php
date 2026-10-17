<?php

namespace App\Mdm\Services;

use App\Mdm\AlertMetric;
use App\Mdm\Models\Alert;
use App\Mdm\Models\AlertRule;
use App\Mdm\Models\CrewHandover;
use App\Mdm\Models\Device;
use App\Mdm\Models\RosterEntry;
use App\Mdm\Models\Shift;
use App\Mdm\Models\UsageSample;
use App\Mdm\Notifications\AlertsTriggered;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Checks the enabled alert rules (every 5 minutes, routes/console.php). Each finding is raised once:
 * - conditions (offline, battery low, handover lock) stay open while they last and clear by themselves; the same
 *   phone is not alerted again for REPEAT_AFTER_HOURS, so a phone in a dead zone doesn't flood the inbox;
 * - data limits are raised once per phone per day/week;
 * - crew handovers once per window and outcome.
 * New alerts of one rule go out together in one email (and one bell notification) to the rule's recipients.
 */
class AlertService
{
    public const REPEAT_AFTER_HOURS = 6;

    public function __construct(private HandoverEnforcement $handovers, private CrewHandoverService $crewHandovers) {}

    /** @return int number of new alerts */
    public function check(?Carbon $now = null): int
    {
        $now ??= now();
        $count = 0;
        foreach (AlertRule::query()->where('enabled', true)->get() as $rule) {
            $count += $this->checkRule($rule, $now)->count();
        }

        return $count;
    }

    /** @return Collection<int, Alert> the new alerts */
    public function checkRule(AlertRule $rule, Carbon $now): Collection
    {
        $findings = collect($this->findings($rule, $now))->keyBy('key');
        $open = $rule->alerts()->whereNull('resolved_at')->get()->keyBy('key');

        if ($rule->metric->isCondition()) {
            $rule->alerts()->whereNull('resolved_at')->whereNotIn('key', $findings->keys()->all())->update(['resolved_at' => $now]);
        }

        $new = new EloquentCollection;
        foreach ($findings as $key => $finding) {
            if ($open->has($key) || ($rule->metric->isCondition() ? $this->raisedRecently($rule, $key, $now) : $rule->alerts()->where('key', $key)->exists())) {
                continue;
            }
            $new->push($rule->alerts()->create($finding + ['triggered_at' => $now]));
        }

        if ($new->isNotEmpty()) {
            $this->notify($rule, $new);
        }

        return $new;
    }

    private function raisedRecently(AlertRule $rule, string $key, Carbon $now): bool
    {
        return $rule->alerts()->where('key', $key)->where('triggered_at', '>', $now->copy()->subHours(self::REPEAT_AFTER_HOURS))->exists();
    }

    private function notify(AlertRule $rule, Collection $alerts): void
    {
        $alerts->load('device');
        Notification::send($rule->recipientUsers(), new AlertsTriggered($rule, $alerts));
        foreach ($rule->extraEmails() as $email) {
            Notification::route('mail', $email)->notify(new AlertsTriggered($rule, $alerts));
        }
        Alert::query()->whereKey($alerts->modelKeys())->update(['notified_at' => now()]);
    }

    /** @return list<array{key: string, device_id: ?int, value: ?float, message: string}> */
    private function findings(AlertRule $rule, Carbon $now): array
    {
        return match ($rule->metric) {
            AlertMetric::Offline => $this->offline($rule, $now),
            AlertMetric::HotspotData, AlertMetric::MobileData => $this->dataOver($rule, $now),
            AlertMetric::BatteryLow => $this->batteryLow($rule, $now),
            AlertMetric::HandoverLock => $this->handoverLock($rule, $now),
            AlertMetric::HandoverMissed => $this->handoverMissed($rule, $now),
        };
    }

    private function phones(AlertRule $rule): Builder
    {
        return Device::query()
            ->where('state', Device::STATE_ENROLLED)
            ->when($rule->group_id, fn (Builder $q) => $q->where('group_id', $rule->group_id));
    }

    private function offline(AlertRule $rule, Carbon $now): array
    {
        $since = $now->copy()->subMinutes($rule->threshold);

        return $this->phones($rule)
            ->where(fn (Builder $q) => $q->where('last_checkin_at', '<', $since)
                ->orWhere(fn (Builder $q) => $q->whereNull('last_checkin_at')->where('enrolled_at', '<', $since)))
            ->get()
            ->map(function (Device $device) use ($now) {
                $last = $device->last_checkin_at ?? $device->enrolled_at;

                return [
                    'key' => "device:{$device->id}",
                    'device_id' => $device->id,
                    'value' => (float) $last->diffInMinutes($now),
                    'message' => "{$device->asset_tag} has not checked in for ".$last->diffForHumans($now, Carbon::DIFF_ABSOLUTE)
                        .' (last at '.$this->time($last).').',
                ];
            })->values()->all();
    }

    private function dataOver(AlertRule $rule, Carbon $now): array
    {
        $local = $now->copy()->setTimezone(config('app.display_timezone'));
        $start = ($rule->window === 'week' ? $local->startOfWeek(Carbon::MONDAY) : $local->startOfDay())->utc();
        $hotspot = $rule->metric === AlertMetric::HotspotData;
        $limit = $rule->threshold * 1024 * 1024;

        $totals = UsageSample::query()
            ->whereIn('device_id', $this->phones($rule)->select('id'))
            ->where('period_start', '>=', $start)
            ->where('network_type', 'mobile')
            ->when($hotspot, fn (Builder $q) => $q->where('package_name', UsageReport::TETHERING))
            ->groupBy('device_id')
            ->selectRaw('device_id, sum(rx_bytes + tx_bytes) as bytes')
            ->havingRaw('sum(rx_bytes + tx_bytes) > ?', [$limit])
            ->pluck('bytes', 'device_id');

        $period = $rule->window === 'week' ? 'this week' : 'today';
        $what = $hotspot ? 'shared through its hotspot' : 'used on mobile data';

        return Device::query()->whereKey($totals->keys())->get()->map(fn (Device $device) => [
            'key' => "device:{$device->id}@".$start->copy()->setTimezone(config('app.display_timezone'))->toDateString(),
            'device_id' => $device->id,
            'value' => round($totals[$device->id] / 1024 / 1024, 2),
            'message' => "{$device->asset_tag} has {$what} ".UsageReport::bytes((int) $totals[$device->id])." {$period} (limit {$rule->threshold} MB).",
        ])->values()->all();
    }

    private function batteryLow(AlertRule $rule, Carbon $now): array
    {
        if (Shift::query()->where('active', true)->exists() && ! Shift::at($now)) {
            return []; // between shifts nobody is using the phones
        }

        return $this->phones($rule)
            ->where('last_checkin_at', '>=', $now->copy()->subMinutes(FleetHealth::SILENT_AFTER_MINUTES))
            ->where('battery_percent', '<=', $rule->threshold)
            ->where(fn (Builder $q) => $q->where('battery_charging', false)->orWhereNull('battery_charging'))
            ->get()
            ->map(fn (Device $device) => [
                'key' => "device:{$device->id}",
                'device_id' => $device->id,
                'value' => (float) $device->battery_percent,
                'message' => "{$device->asset_tag} is at {$device->battery_percent}% and not charging.",
            ])->values()->all();
    }

    private function handoverLock(AlertRule $rule, Carbon $now): array
    {
        $window = $this->handovers->lastClosedWindow($now);
        if (! $window || $window['end']->gt($now->copy()->subMinutes($rule->threshold))) {
            return [];
        }

        return $this->phones($rule)->with('group.policy')->get()
            ->filter(fn (Device $device) => $this->handovers->isLocked($device))
            ->map(fn (Device $device) => [
                'key' => "device:{$device->id}",
                'device_id' => $device->id,
                'value' => (float) $window['end']->diffInMinutes($now),
                'message' => "{$device->asset_tag} has been locked since ".$this->time($window['end'])
                    ." because nobody recorded its handover for {$window['shift']->name}.",
            ])->values()->all();
    }

    private function handoverMissed(AlertRule $rule, Carbon $now): array
    {
        // The last window that closed at least `threshold` minutes ago; only where the roster has a crew on it.
        $window = $this->handovers->lastClosedWindow($now->copy()->subMinutes($rule->threshold));
        if (! $window || ! RosterEntry::query()->where('shift_id', $window['shift']->id)->whereDate('date', $window['date'])->exists()) {
            return [];
        }

        $handover = CrewHandover::query()->with(['items.device', 'toCrew'])
            ->where('shift_id', $window['shift']->id)->whereDate('date', $window['date'])->first();
        $shift = "{$window['shift']->name} on ".Carbon::parse($window['date'])->format('D j M');
        [$outcome, $message] = match (true) {
            ! $handover => ['missing', "No crew handover was recorded for {$shift}."],
            $handover->isPending() => ['pending', "The crew handover for {$shift} was handed over but not accepted by {$handover->toCrew->name}."],
            $handover->status === CrewHandover::DISPUTED => ['disputed', "{$handover->toCrew->name} did not receive "
                .$handover->items->where('received', false)->map(fn ($i) => $i->device->asset_tag)->implode(', ')." at the handover for {$shift}."],
            default => [null, null],
        };

        return $outcome ? [[
            'key' => "handover:{$window['shift']->id}@{$window['date']}:{$outcome}",
            'device_id' => null,
            'value' => null,
            'message' => $message,
        ]] : [];
    }

    private function time(Carbon $at): string
    {
        return $at->copy()->setTimezone(config('app.display_timezone'))->format('D H:i');
    }
}
