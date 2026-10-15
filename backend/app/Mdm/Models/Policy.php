<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Our own versioned policy JSON (ARCHITECTURE.md §5), e.g. {"hotspot": "blocked", "blocked_apps": ["com.zhiliaoapp.musically"]}.
 * Resolved with per-device overrides only by PolicyBuilder.
 */
#[Fillable(['name', 'payload', 'version'])]
class Policy extends Model
{
    protected $table = 'mdm_policies';

    /** Matches the column default, so a just-created policy knows its version before it is reloaded. */
    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    protected static function booted(): void
    {
        // Phones compare versions to know when to re-apply; any payload change must bump it.
        static::updating(function (Policy $policy) {
            if ($policy->isDirty('payload')) {
                $policy->version++;
            }
        });
        static::updated(function (Policy $policy) {
            if ($policy->wasChanged('payload')) {
                AuditLog::record('policy.updated', $policy, [
                    'groups' => $policy->groups()->pluck('name')->join(', '),
                    'from' => $policy->getOriginal('payload'),
                    'to' => $policy->payload,
                ]);
            }
        });
    }

    public function groups(): HasMany
    {
        return $this->hasMany(DeviceGroup::class, 'policy_id');
    }
}
