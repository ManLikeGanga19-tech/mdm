<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Small editable settings stored as JSON, with defaults when unset. */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const DEFAULTS = [
        // Busy hours in the admin's time zone, used to split reports into peak and off-peak.
        'peak_hours' => [['07:00', '10:00'], ['16:00', '19:00']],
        // Days to keep network logs, status reports and raw uploads.
        'retention_days' => 90,
    ];

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $table = 'mdm_settings';

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key): mixed
    {
        // Wrapped so scalars survive the JSON column: {"v": ...}.
        return static::query()->find($key)?->value['v'] ?? self::DEFAULTS[$key] ?? null;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
    }
}
