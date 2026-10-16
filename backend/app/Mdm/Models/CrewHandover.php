<?php

namespace App\Mdm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The phones passing from the outgoing crew to the incoming one at the start of a shift: handed over by an outgoing
 * coordinator, then accepted phone by phone by an incoming coordinator (CrewHandoverService).
 */
#[Fillable(['shift_id', 'date', 'from_crew_id', 'to_crew_id', 'handed_by', 'handed_at', 'accepted_by', 'accepted_at', 'status', 'note'])]
class CrewHandover extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DISPUTED = 'disputed'; // accepted, but some phones were not received

    protected $table = 'mdm_crew_handovers';

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'handed_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function fromCrew(): BelongsTo
    {
        return $this->belongsTo(Crew::class, 'from_crew_id');
    }

    public function toCrew(): BelongsTo
    {
        return $this->belongsTo(Crew::class, 'to_crew_id');
    }

    public function handedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_by');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CrewHandoverItem::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
