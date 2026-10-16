<?php

namespace App\Mdm\Http\Controllers\Device;

use App\Mdm\Models\Command;
use App\Mdm\Models\Device;
use App\Mdm\Services\HandoverService;
use App\Mdm\Services\PolicyBuilder;
use App\Mdm\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/device/v1/checkin — the phone reports its status and receives its effective policy and pending
 * commands. Commands handed out are marked delivered; ones not yet finished are handed out again (the agent
 * ignores uuids it has already run).
 */
class CheckinController
{
    public const INTERVAL_SECONDS = 900;

    public function __invoke(Request $request, PolicyBuilder $policies, HandoverService $handovers, PushService $push): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $data = $request->validate([
            'battery_percent' => ['nullable', 'integer', 'between:0,100'],
            'battery_charging' => ['nullable', 'boolean'],
            'network_type' => ['nullable', 'in:wifi,mobile,none,other'],
            'android_version' => ['nullable', 'string', 'max:16'],
            'security_patch' => ['nullable', 'string', 'max:16'],
            'agent_version_code' => ['nullable', 'integer', 'min:0'],
            'agent_version_name' => ['nullable', 'string', 'max:32'],
            'applied_policy_version' => ['nullable', 'integer', 'min:0'],
            'notice_acknowledged_at' => ['nullable', 'date'],
            'fcm_token' => ['nullable', 'string', 'max:4096'],
            'serial' => ['nullable', 'string', 'max:64'],
            'imei' => ['nullable', 'string', 'max:32'],
        ]);

        // A serial already on another phone's record (a phone re-enrolled under a new tag) is left for the admin to
        // sort out rather than failing the check-in on the unique index.
        if (filled($data['serial'] ?? null) && Device::query()->where('serial', $data['serial'])->whereKeyNot($device->id)->exists()) {
            unset($data['serial']);
        }

        $device->fill(array_filter($data, fn ($v) => $v !== null) + ['last_checkin_at' => now()]);
        if (array_key_exists('applied_policy_version', $data)) {
            $device->applied_policy_version = $data['applied_policy_version'];
        }
        $device->save();
        $push->checkedIn($device);

        // Battery and network history (Milestone B), one row per check-in.
        $device->statusReports()->create([
            'battery_percent' => $data['battery_percent'] ?? null,
            'battery_charging' => $data['battery_charging'] ?? null,
            'network_type' => $data['network_type'] ?? null,
            'reported_at' => now(),
        ]);

        $commands = $device->commands()->whereIn('status', ['queued', 'delivered'])->oldest()->get();
        Command::query()->whereKey($commands->where('status', 'queued')->modelKeys())
            ->update(['status' => 'delivered', 'delivered_at' => now()]);

        return response()->json([
            'policy' => $policies->build($device),
            'commands' => $commands->map(fn (Command $c) => [
                'uuid' => $c->uuid,
                'type' => $c->type,
                'payload' => $c->payload ?? (object) [],
            ])->values(),
            'checkin_interval_seconds' => self::INTERVAL_SECONDS,
            // For the staff notice: the clerk is attached at the shift handover, so the phone learns it from here.
            'asset_tag' => $device->asset_tag,
            'holder' => $handovers->holderAt($device, now())?->name,
            'server_time' => now()->toIso8601ZuluString(),
            // The real-time channel (hub, topic, token); the agent keeps a connection open to it for instant wake-ups.
            'realtime' => $push->realtimeFor($device),
        ]);
    }
}
