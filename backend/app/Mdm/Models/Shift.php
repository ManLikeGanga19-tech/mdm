<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A shift (times in the admin's time zone; may cross midnight). It starts with a handover window of
 * handover_minutes in which the outgoing shift coordinator hands the phones to the incoming one.
 */
#[Fillable(['name', 'starts_at', 'ends_at', 'handover_minutes', 'active'])]
class Shift extends Model
{
    protected $table = 'mdm_shifts';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'handover_minutes' => 'integer'];
    }

    /**
     * The UTC start and end of this shift on $date (the date it starts on, in the admin's time zone).
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

    /** @return array{0: Carbon, 1: Carbon} UTC start and end of the handover window that opens the shift on $date. */
    public function handoverWindow(string $date): array
    {
        [$start] = $this->window($date);

        return [$start, $start->copy()->addMinutes($this->handover_minutes)];
    }

    /**
     * The shift running at $at, with the date it started on (admin's time zone).
     *
     * @return array{0: self, 1: string}|null
     */
    public static function at(Carbon $at): ?array
    {
        $tz = config('app.display_timezone');
        foreach (static::query()->where('active', true)->get() as $shift) {
            foreach ([0, 1] as $daysBack) {
                $date = $at->copy()->setTimezone($tz)->subDays($daysBack)->toDateString();
                [$start, $end] = $shift->window($date);
                if ($at->gte($start) && $at->lt($end)) {
                    return [$shift, $date];
                }
            }
        }

        return null;
    }

    public function label(): string
    {
        return sprintf('%s (%s–%s)', $this->name, substr($this->starts_at, 0, 5), substr($this->ends_at, 0, 5));
    }
}
