<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** A shift definition (times in the admin's time zone); may cross midnight. */
#[Fillable(['name', 'starts_at', 'ends_at', 'active'])]
class Shift extends Model
{
    protected $table = 'mdm_shifts';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * The UTC start and end of this shift on $date (a date in the admin's time zone).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function window(string $date): array
    {
        $tz = config('app.display_timezone');
        $start = Carbon::parse("{$date} {$this->starts_at}", $tz);
        $end = Carbon::parse("{$date} {$this->ends_at}", $tz);
        if ($end->lte($start)) {
            $end->addDay(); // night shift
        }

        return [$start->utc(), $end->utc()];
    }

    public function label(): string
    {
        return sprintf('%s (%s–%s)', $this->name, substr($this->starts_at, 0, 5), substr($this->ends_at, 0, 5));
    }
}
