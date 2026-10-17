<?php

namespace App\Mdm\Models;

use App\Mdm\AlertMetric;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** "Email these people when this happens", checked every 5 minutes by AlertService. */
#[Fillable(['name', 'metric', 'threshold', 'window', 'group_id', 'recipient_role_ids', 'recipient_emails', 'enabled'])]
class AlertRule extends Model
{
    protected $table = 'mdm_alert_rules';

    protected function casts(): array
    {
        return [
            'metric' => AlertMetric::class,
            'threshold' => 'integer',
            'recipient_role_ids' => 'array',
            'recipient_emails' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'group_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'rule_id');
    }

    /** Active accounts with one of the rule's roles. */
    public function recipientUsers(): Collection
    {
        return User::query()->whereNull('disabled_at')->whereIn('role_id', $this->recipient_role_ids ?? [])->get();
    }

    /** Extra addresses that don't belong to one of the recipient accounts (those already get it). */
    public function extraEmails(): array
    {
        $accounts = $this->recipientUsers()->pluck('email')->map(fn ($e) => strtolower($e))->all();

        return collect($this->recipient_emails ?? [])->map(fn ($e) => strtolower(trim($e)))->filter()
            ->unique()->reject(fn ($e) => in_array($e, $accounts, true))->values()->all();
    }

    /** "60 minutes", "1024 MB per day". */
    public function describeThreshold(): string
    {
        $text = "{$this->threshold} {$this->metric->unit()}";

        return $this->metric->usesWindow() ? "{$text} per {$this->window}" : $text;
    }
}
