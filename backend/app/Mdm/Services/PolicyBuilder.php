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
    ];

    /** @return array{version:int, hotspot:string, blocked_apps:list<string>, network_logging:bool} */
    public function build(Device $device): array
    {
        $policy = $device->group?->policy;
        $effective = array_replace(self::DEFAULTS, $policy?->payload ?? [], $device->policy_overrides ?? []);
        $effective['blocked_apps'] = array_values(array_unique($effective['blocked_apps']));

        // Changes to either the group policy or the overrides must change the version the phone compares against.
        // Masked to 31 bits: it must fit Postgres `integer` (applied_policy_version) and the agent's Kotlin Int.
        $effective['version'] = crc32(json_encode([$policy?->id, $policy?->version, $device->policy_overrides])) & 0x7FFFFFFF;

        return $effective;
    }
}
