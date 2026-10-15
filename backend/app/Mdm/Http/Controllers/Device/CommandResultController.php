<?php

namespace App\Mdm\Http\Controllers\Device;

use App\Mdm\Models\AuditLog;
use App\Mdm\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/device/v1/commands/{uuid}/result — outcome of a command. Repeating a result is harmless. */
class CommandResultController
{
    public function __invoke(Request $request, string $uuid): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $command = $device->commands()->where('uuid', $uuid)->first();
        if (! $command) {
            return response()->json(['message' => 'Unknown command.'], 404);
        }

        $data = $request->validate([
            'status' => ['required', 'in:succeeded,failed'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        if (in_array($command->status, ['succeeded', 'failed', 'expired'], true)) {
            return response()->json(['status' => $command->status]);
        }

        $command->update([
            'status' => $data['status'],
            'completed_at' => now(),
            'delivered_at' => $command->delivered_at ?? now(),
            'result' => filled($data['message'] ?? null) ? ['message' => $data['message']] : null,
        ]);
        AuditLog::record('command.'.$data['status'], $device, [
            'asset_tag' => $device->asset_tag, 'command' => $command->type, 'message' => $data['message'] ?? null,
        ]);

        return response()->json(['status' => $command->status]);
    }
}
