<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which crew works a shift on a date (the date the shift starts, admin's time zone). */
#[Fillable(['date', 'shift_id', 'crew_id'])]
class RosterEntry extends Model
{
    protected $table = 'mdm_roster';

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class);
    }
}
