<?php

namespace App\Mdm\Models;

use App\Mdm\Services\LiveView;
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
        $entry = self::create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'data' => $data ?: null,
            'created_at' => now(),
        ]);
        // What an admin does to a phone also shows in its live console, if one is open.
        if ($subject instanceof Device) {
            app(LiveView::class)->event($subject, $entry->summary());
        }

        return $entry;
    }

    /** One readable line: "Handover: Jane W. → Peter O. (by Daniel)". */
    public function summary(): string
    {
        $d = $this->data ?? [];
        $text = match (true) {
            $this->action === 'handover' => 'Handover: '.($d['from'] ?? 'nobody').' → '.($d['to'] ?? '?'),
            $this->action === 'phone_returned' => 'Phone returned by '.($d['from'] ?? '?'),
            str_starts_with($this->action, 'command.') => 'Command sent: '.(Command::TYPES[substr($this->action, 8)] ?? substr($this->action, 8)),
            $this->action === 'policy.override' => 'Rule changed: '.($d['field'] ?? '?').' '.json_encode($d['from'] ?? null).' → '.json_encode($d['to'] ?? null),
            in_array($this->action, ['app.blocked', 'app.allowed'], true) => ($this->action === 'app.blocked' ? 'App blocked: ' : 'App allowed: ').($d['package'] ?? '?'),
            $this->action === 'handover_lock.released' => 'Handover lock released by an admin',
            $this->action === 'live_view.started' => 'Live view started',
            default => str_replace(['.', '_'], ' ', ucfirst($this->action)),
        };

        return $text.($this->actor ? ' (by '.$this->actor->name.')' : '');
    }
}
