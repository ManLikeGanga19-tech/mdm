<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One DNS lookup or connection from Android's network logging. */
#[Fillable(['device_id', 'log_type', 'package_name', 'host', 'ip', 'port', 'occurred_at'])]
class NetworkLog extends Model
{
    public $timestamps = false;

    protected $table = 'mdm_network_logs';

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
