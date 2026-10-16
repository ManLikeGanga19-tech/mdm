<?php

namespace App\Mdm\Services;

use App\Mdm\Models\AuditLog;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceAssignment;
use App\Mdm\Models\Shift;
use Illuminate\Support\Carbon;

/**
 * Handover lock: in groups that enforce it (policy payload handover_lock), a phone that was not handed over in the
 * last closed handover window is locked on a full-screen notice until a coordinator records its handover (or a super
 * admin releases it for that window). Evaluated by PolicyBuilder, so the phone picks it up like any other rule.
 */
class HandoverEnforcement
{
    /**
     * The most recent handover window that has already closed.
     *
     * @return array{shift: Shift, date: string, start: Carbon, end: Carbon}|null
     */
    public function lastClosedWindow(?Carbon $now = null): ?array
    {
        $now ??= now();
        $tz = config('app.display_timezone');
        $latest = null;
        foreach (Shift::query()->where('active', true)->where('handover_minutes', '>', 0)->get() as $shift) {
            foreach ([0, 1] as $daysBack) {
                $date = $now->copy()->setTimezone($tz)->subDays($daysBack)->toDateString();
                [$start, $end] = $shift->handoverWindow($date);
                if ($end->lte($now) && (! $latest || $end->gt($latest['end']))) {
                    $latest = ['shift' => $shift, 'date' => $date, 'start' => $start, 'end' => $end];
                }
            }
        }

        return $latest;
    }

    public function enforced(Device $device): bool
    {
        return $device->state === Device::STATE_ENROLLED && (bool) ($device->group?->policy?->payload['handover_lock'] ?? false);
    }

    /** Locked = enforced, and no handover since the last closed window opened (early handovers count), and not released. */
    public function isLocked(Device $device): bool
    {
        if (! $this->enforced($device) || ! ($window = $this->lastClosedWindow())) {
            return false;
        }
        if (($device->policy_overrides['handover_released_for'] ?? null) === $this->windowKey($window)) {
            return false;
        }

        return ! DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->where('started_at', '>=', $window['start']->copy()->subMinutes(ShiftReport::EARLY_GRACE_MINUTES))
            ->exists();
    }

    /** A super admin lets a locked phone be used until the next handover window closes. */
    public function release(Device $device): void
    {
        if (! $window = $this->lastClosedWindow()) {
            return;
        }
        $device->update(['policy_overrides' => array_merge($device->policy_overrides ?? [], ['handover_released_for' => $this->windowKey($window)])]);
        AuditLog::record('handover_lock.released', $device, ['asset_tag' => $device->asset_tag, 'window' => $this->windowKey($window)]);
        app(PushService::class)->wake($device);
    }

    /** Phones to wake right after a handover window closes, so the lock applies within seconds. */
    public function windowJustClosed(Carbon $now, int $withinSeconds = 60): bool
    {
        $window = $this->lastClosedWindow($now);

        return $window !== null && $window['end']->gt($now->copy()->subSeconds($withinSeconds));
    }

    private function windowKey(array $window): string
    {
        return $window['shift']->id.'@'.$window['date'];
    }
}
