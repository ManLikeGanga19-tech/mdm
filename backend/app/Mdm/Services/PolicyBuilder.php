<?php

namespace App\Mdm\Services;

use App\Mdm\Models\Device;

/**
 * The only place policy is resolved (CLAUDE.md rule 5): defaults <- group policy <- per-device overrides.
 * The agent's PolicyEnforcer applies the result and reports `version` back as applied_policy_version.
 */
class PolicyBuilder
{
    /** Safe defaults for a phone with no group: hotspot allowed, nothing blocked. */
    public const DEFAULTS = [
        'hotspot' => 'allowed',      // 'blocked' | 'allowed' (allowed is always monitored)
        'blocked_apps' => [],        // package names, suspended on the phone
        'network_logging' => true,
        'handover_lock' => false,    // group setting: lock phones not handed over (HandoverEnforcement)
    ];

    /** @return array{version:int, hotspot:string, blocked_apps:list<string>, network_logging:bool} */
    public function build(Device $device): array
    {
        $policy = $device->group?->policy;
        $overrides = $device->policy_overrides ?? [];
        // Per-phone app exceptions adjust the group's list instead of replacing it, so later group changes still apply.
        $add = $overrides['blocked_apps_add'] ?? [];
        $remove = $overrides['blocked_apps_remove'] ?? [];
        unset($overrides['blocked_apps_add'], $overrides['blocked_apps_remove'], $overrides['handover_released_for']);

        $effective = array_replace(self::DEFAULTS, $policy?->payload ?? [], $overrides);
        $effective['blocked_apps'] = array_values(array_diff(array_unique(array_merge($effective['blocked_apps'], $add)), $remove));

        // The group setting says whether to enforce; what the phone gets is whether it is locked right now.
        $effective['handover_lock'] = app(HandoverEnforcement::class)->isLocked($device);

        // Changes to the group policy, the overrides or the lock state must change the version the phone compares
        // against. Masked to 31 bits: it must fit Postgres `integer` (applied_policy_version) and the agent's Kotlin Int.
        $effective['version'] = crc32(json_encode([$policy?->id, $policy?->version, $device->policy_overrides, $effective['handover_lock']])) & 0x7FFFFFFF;

        return $effective;
    }
}
