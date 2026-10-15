<?php

namespace App\Mdm\Http\Controllers\Device;

use App\Mdm\Models\Command;
use App\Mdm\Models\Device;
use App\Mdm\Services\PolicyBuilder;
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

    public function __invoke(Request $request, PolicyBuilder $policies): JsonResponse
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
        ]);

        $device->fill(array_filter($data, fn ($v) => $v !== null) + ['last_checkin_at' => now()]);
        if (array_key_exists('applied_policy_version', $data)) {
            $device->applied_policy_version = $data['applied_policy_version'];
        }
        $device->save();

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
            'server_time' => now()->toIso8601ZuluString(),
        ]);
    }
}
