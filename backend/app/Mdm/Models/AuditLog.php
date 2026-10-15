<?php

namespace App\Mdm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who did what: commands, policy changes, handovers (ARCHITECTURE.md §8). Append-only. */
#[Fillable(['actor_id', 'action', 'subject_type', 'subject_id', 'data', 'created_at'])]
class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'mdm_audit_log';

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(string $action, ?Model $subject = null, array $data = []): self
    {
        return self::create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'data' => $data ?: null,
            'created_at' => now(),
        ]);
    }
}
