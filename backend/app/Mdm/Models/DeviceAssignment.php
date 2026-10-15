<?php

namespace App\Mdm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One clerk holding one phone from a handover until the next (ended_at null = still holding it). */
#[Fillable(['device_id', 'clerk_id', 'started_at', 'ended_at', 'assigned_by', 'ended_by', 'note'])]
class DeviceAssignment extends Model
{
    protected $table = 'mdm_device_assignments';

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function clerk(): BelongsTo
    {
        return $this->belongsTo(Clerk::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
