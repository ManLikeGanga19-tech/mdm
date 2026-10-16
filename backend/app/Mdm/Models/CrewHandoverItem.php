<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One phone in a crew handover, with the outgoing note and whether the incoming crew received it. */
#[Fillable(['crew_handover_id', 'device_id', 'note', 'received', 'receive_note'])]
class CrewHandoverItem extends Model
{
    protected $table = 'mdm_crew_handover_items';

    protected function casts(): array
    {
        return ['received' => 'boolean'];
    }

    public function handover(): BelongsTo
    {
        return $this->belongsTo(CrewHandover::class, 'crew_handover_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
