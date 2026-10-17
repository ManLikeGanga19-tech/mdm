<?php

namespace App\Mdm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One time a rule fired, for one phone (or one crew handover). Open until it clears by itself or someone acknowledges it. */
#[Fillable(['rule_id', 'device_id', 'key', 'value', 'message', 'triggered_at', 'notified_at', 'resolved_at', 'acknowledged_at', 'acknowledged_by'])]
class Alert extends Model
{
    public $timestamps = false;

    protected $table = 'mdm_alerts';

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'triggered_at' => 'datetime',
            'notified_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'rule_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at')->whereNull('acknowledged_at');
    }

    public function status(): string
    {
        return match (true) {
            $this->acknowledged_at !== null => 'Acknowledged',
            $this->resolved_at !== null => 'Cleared',
            default => 'Open',
        };
    }
}
