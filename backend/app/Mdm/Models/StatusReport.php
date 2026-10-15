<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Battery and network at one check-in. */
#[Fillable(['device_id', 'battery_percent', 'battery_charging', 'network_type', 'reported_at'])]
class StatusReport extends Model
{
    public $timestamps = false;

    protected $table = 'mdm_status_reports';

    protected function casts(): array
    {
        return ['battery_charging' => 'boolean', 'reported_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
