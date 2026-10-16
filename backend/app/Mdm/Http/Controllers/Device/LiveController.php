<?php

namespace App\Mdm\Http\Controllers\Device;

use App\Mdm\Models\Device;
use App\Mdm\Services\LiveView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/device/v1/live — live view samples (every ~2 s) with the apps using the most data, and the phone's event
 * lines for the live console; answers whether to continue.
 */
class LiveController
{
    public function __invoke(Request $request, LiveView $live): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $data = $request->validate([
            'samples' => ['present', 'array', 'max:30'],
            'samples.*.apps' => ['sometimes', 'array', 'max:10'],
            'samples.*.apps.*.package' => ['required', 'string', 'max:255'],
            'samples.*.apps.*.label' => ['required', 'string', 'max:100'],
            'samples.*.apps.*.rx_bps' => ['required', 'integer', 'min:0'],
            'samples.*.apps.*.tx_bps' => ['required', 'integer', 'min:0'],
            'samples.*.apps_age_s' => ['sometimes', 'integer', 'min:0'],
            'events' => ['sometimes', 'array', 'max:100'],
            'events.*.at' => ['required', 'date'],
            'events.*.text' => ['required', 'string', 'max:300'],
            'samples.*.at' => ['required', 'date'],
            'samples.*.rx_bps' => ['required', 'integer', 'min:0'],
            'samples.*.tx_bps' => ['required', 'integer', 'min:0'],
            'samples.*.network' => ['nullable', 'in:wifi,mobile,none,other'],
            'samples.*.signal_dbm' => ['nullable', 'integer', 'between:-200,0'],
            'samples.*.down_kbps' => ['nullable', 'integer', 'min:0'],
            'samples.*.up_kbps' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(['continue' => $live->record($device, $data['samples'], $data['events'] ?? [])]);
    }
}
