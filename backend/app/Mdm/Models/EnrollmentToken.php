<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Admits new phones at enrollment; created on the admin site, pasted into NoahportEnroll.exe. Stored hashed. */
#[Fillable(['label', 'token_hash', 'group_id', 'max_uses', 'uses', 'expires_at', 'created_by'])]
class EnrollmentToken extends Model
{
    protected $table = 'mdm_enrollment_tokens';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'group_id');
    }
}
