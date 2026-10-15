<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'asset_tag', 'serial', 'imei', 'manufacturer', 'model', 'android_version', 'security_patch',
    'agent_version_code', 'agent_version_name', 'group_id', 'policy_overrides', 'applied_policy_version',
    'fcm_token', 'state', 'enrolled_at', 'last_checkin_at', 'notice_acknowledged_at',
    'battery_percent', 'battery_charging', 'network_type',
])]
class Device extends Model
{
    use HasUuids;

    public const STATE_PENDING = 'pending';

    public const STATE_ENROLLED = 'enrolled';

    public const STATE_RETIRED = 'retired';

    protected $table = 'mdm_devices';

    /** Matches the column default, so a just-created phone knows it is pending before it is reloaded. */
    protected $attributes = ['state' => self::STATE_PENDING];

    /** `id` stays the integer key; only `uuid` is generated. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'policy_overrides' => 'array',
            'enrolled_at' => 'datetime',
            'last_checkin_at' => 'datetime',
            'notice_acknowledged_at' => 'datetime',
            'battery_charging' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'group_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class)->latest('started_at');
    }

    /** The clerk holding the phone right now, if any. */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(DeviceAssignment::class)->whereNull('ended_at');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(Command::class)->latest();
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(DeviceCredential::class);
    }
}
