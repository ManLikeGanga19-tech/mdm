<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'policy_id'])]
class DeviceGroup extends Model
{
    protected $table = 'mdm_device_groups';

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'group_id');
    }
}
