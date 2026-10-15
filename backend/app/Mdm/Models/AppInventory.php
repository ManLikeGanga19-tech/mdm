<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An app installed on a phone, with its display name. */
#[Fillable(['device_id', 'package_name', 'label', 'version_name', 'version_code', 'system', 'last_seen_at'])]
class AppInventory extends Model
{
    public $timestamps = false;

    protected $table = 'mdm_app_inventory';

    protected function casts(): array
    {
        return ['system' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
