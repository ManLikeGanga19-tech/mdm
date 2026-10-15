<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A device's API credential, stored as a SHA-256 hash. */
#[Fillable(['device_id', 'token_hash', 'created_at', 'revoked_at'])]
class DeviceCredential extends Model
{
    public $timestamps = false;

    protected $table = 'mdm_device_credentials';

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
