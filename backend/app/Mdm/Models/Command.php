<?php

namespace App\Mdm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A remote action for one phone. Lifecycle: queued -> delivered -> succeeded | failed | expired. */
#[Fillable(['device_id', 'type', 'payload', 'status', 'requested_by', 'delivered_at', 'completed_at', 'result'])]
class Command extends Model
{
    use HasUuids;

    public const TYPES = [
        'lock' => 'Lock now',
        'reboot' => 'Restart',
        'reset_password' => 'Reset screen lock',
        'wipe' => 'Erase phone',
        'live_view' => 'Live view',
    ];

    protected $table = 'mdm_commands';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function label(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
