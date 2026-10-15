<?php

namespace App\Mdm\Http\Controllers\Device;

use App\Mdm\Jobs\ProcessIngestBatch;
use App\Mdm\Models\Device;
use App\Mdm\Models\IngestBatch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/device/v1/{usage|network-logs|inventory} — telemetry uploads (CLAUDE.md rule 4): validate the shape,
 * store the raw batch, queue processing, answer 202. A batch_uuid already received answers 200 and is ignored, so
 * the phone can safely retry after a lost response.
 */
class IngestController
{
    private const RULES = [
        'usage' => [
            'samples' => ['required', 'array', 'max:5000'],
            'samples.*.package' => ['required', 'string', 'max:255'],
            'samples.*.network' => ['required', 'in:mobile,wifi'],
            'samples.*.rx' => ['required', 'integer', 'min:0'],
            'samples.*.tx' => ['required', 'integer', 'min:0'],
            'samples.*.start' => ['required', 'date'],
            'samples.*.end' => ['required', 'date'],
        ],
        'network_logs' => [
            'events' => ['required', 'array', 'max:5000'],
            'events.*.type' => ['required', 'in:dns,connect'],
            'events.*.package' => ['nullable', 'string', 'max:255'],
            'events.*.host' => ['nullable', 'string', 'max:255'],
            'events.*.ip' => ['nullable', 'string', 'max:64'],
            'events.*.port' => ['nullable', 'integer', 'between:0,65535'],
            'events.*.at' => ['required', 'date'],
        ],
        'inventory' => [
            'apps' => ['required', 'array', 'max:2000'],
            'apps.*.package' => ['required', 'string', 'max:255'],
            'apps.*.label' => ['nullable', 'string', 'max:255'],
            'apps.*.version_name' => ['nullable', 'string', 'max:100'],
            'apps.*.version_code' => ['nullable', 'integer', 'min:0'],
            'apps.*.system' => ['nullable', 'boolean'],
        ],
    ];

    public function __invoke(Request $request, string $kind): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $data = $request->validate(['batch_uuid' => ['required', 'uuid']] + self::RULES[$kind]);

        if (IngestBatch::query()->where('batch_uuid', $data['batch_uuid'])->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            $batch = IngestBatch::create([
                'device_id' => $device->id,
                'batch_uuid' => $data['batch_uuid'],
                'kind' => $kind,
                'raw' => $data,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']); // the same batch arrived twice at once
        }

        ProcessIngestBatch::dispatch($batch->id);

        return response()->json(['status' => 'accepted'], 202);
    }
}
