<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bytes one app used on one network in one hour. */
#[Fillable(['device_id', 'package_name', 'network_type', 'rx_bytes', 'tx_bytes', 'period_start', 'period_end'])]
class UsageSample extends Model
{
    public $timestamps = false;

    protected $table = 'mdm_usage_samples';

    protected function casts(): array
    {
        return ['period_start' => 'datetime', 'period_end' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
