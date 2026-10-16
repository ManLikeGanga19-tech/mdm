<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A clerk who uses the shared shift phones. Imported from the company's staff spreadsheet. */
#[Fillable(['staff_number', 'name', 'crew_id', 'active'])]
class Clerk extends Model
{
    protected $table = 'mdm_clerks';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class)->latest('started_at');
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(DeviceAssignment::class)->whereNull('ended_at');
    }

    /** "1043 · Jane Wanjiru", used in pickers and tables. */
    public function label(): string
    {
        return "{$this->staff_number} · {$this->name}";
    }
}
