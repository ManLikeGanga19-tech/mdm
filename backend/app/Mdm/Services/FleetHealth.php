<?php

namespace App\Mdm\Services;

use App\Mdm\Models\Device;
use Illuminate\Support\Carbon;

/**
 * One definition of "this phone needs attention", shared by the dashboard and (Milestone B) the alert emails.
 */
class FleetHealth
{
    /** A phone that hasn't checked in for this long is "silent" (normal check-ins are every ~15 min). */
    public const SILENT_AFTER_MINUTES = 60;

    /** Android security patches older than this are flagged. */
    public const PATCH_MAX_AGE_DAYS = 90;

    public function __construct(private PolicyBuilder $policies) {}

    /** @return list<string> plain-language issues, empty when the phone is fine */
    public function issues(Device $device): array
    {
        if ($device->state === Device::STATE_RETIRED) {
            return [];
        }
        if ($device->state === Device::STATE_PENDING) {
            return ['Not enrolled yet'];
        }

        $issues = [];
        if (! $device->last_checkin_at) {
            $issues[] = 'Never checked in';
        } elseif ($device->last_checkin_at->lt(now()->subMinutes(self::SILENT_AFTER_MINUTES))) {
            $issues[] = 'Silent for '.$device->last_checkin_at->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE);
        }
        if ($device->last_checkin_at && ! $this->rulesApplied($device)) {
            $issues[] = 'Rules not applied yet';
        }
        if ($this->patchIsOld($device)) {
            $issues[] = "Security patch {$device->security_patch}";
        }
        if (! $device->notice_acknowledged_at) {
            $issues[] = 'Staff notice not acknowledged';
        }
        if ($device->commands()->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->exists()) {
            $issues[] = 'A command failed (24 h)';
        }

        return $issues;
    }

    public function isReporting(Device $device): bool
    {
        return $device->last_checkin_at?->gte(now()->subMinutes(self::SILENT_AFTER_MINUTES)) ?? false;
    }

    public function rulesApplied(Device $device): bool
    {
        return $device->applied_policy_version === $this->policies->build($device)['version'];
    }

    public function patchIsOld(Device $device): bool
    {
        if (! $device->security_patch) {
            return false;
        }

        try {
            return Carbon::parse($device->security_patch)->lt(now()->subDays(self::PATCH_MAX_AGE_DAYS));
        } catch (\Throwable) {
            return false;
        }
    }
}
