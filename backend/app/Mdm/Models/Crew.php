<?php

namespace App\Mdm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A crew: two coordinators (admin-site accounts) and their clerks, rotating through the shifts on the roster. */
#[Fillable(['name', 'active'])]
class Crew extends Model
{
    public const MAX_COORDINATORS = 2;

    protected $table = 'mdm_crews';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function coordinators(): HasMany
    {
        return $this->hasMany(User::class, 'crew_id');
    }

    public function clerks(): HasMany
    {
        return $this->hasMany(Clerk::class, 'crew_id');
    }

    /** "Crew 2 (Peter O., Jane W.)" */
    public function labelWithCoordinators(): string
    {
        $names = $this->coordinators->pluck('name')->implode(', ');

        return $this->name.($names ? " ({$names})" : '');
    }
}
