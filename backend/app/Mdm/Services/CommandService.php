<?php

namespace App\Mdm\Services;

use App\Mdm\Models\AuditLog;
use App\Mdm\Models\Command;
use App\Mdm\Models\Device;
use InvalidArgumentException;

/** Queues remote actions and policy changes for a phone, audits them, and wakes the phone. */
class CommandService
{
    public function __construct(private PushService $push) {}

    public function send(Device $device, string $type, array $payload = []): Command
    {
        if (! array_key_exists($type, Command::TYPES)) {
            throw new InvalidArgumentException("Unknown command type: {$type}");
        }

        $command = $device->commands()->create([
            'type' => $type,
            'payload' => $payload ?: null,
            'status' => 'queued',
            'requested_by' => auth()->id(),
        ]);

        AuditLog::record('command.'.$type, $device, ['asset_tag' => $device->asset_tag, 'command' => $command->uuid]);
        $this->push->wake($device);

        return $command;
    }

    /** Sets (or clears, with null) a per-device policy override, e.g. hotspot => 'blocked'. */
    public function overridePolicy(Device $device, string $field, mixed $value): void
    {
        $overrides = $device->policy_overrides ?? [];
        $before = $overrides[$field] ?? null;
        if ($value === null) {
            unset($overrides[$field]);
        } else {
            $overrides[$field] = $value;
        }
        $device->update(['policy_overrides' => $overrides ?: null]);

        AuditLog::record('policy.override', $device, [
            'asset_tag' => $device->asset_tag, 'field' => $field, 'from' => $before, 'to' => $value,
        ]);
        $this->push->wake($device);
    }
}
