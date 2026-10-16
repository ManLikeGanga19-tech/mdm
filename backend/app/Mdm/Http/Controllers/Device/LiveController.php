<?php

namespace App\Mdm\Http\Controllers\Device;

use App\Mdm\Models\Device;
use App\Mdm\Services\LiveView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/device/v1/live — live view samples (every ~2 s, sent in small batches); answers whether to continue. */
class LiveController
{
    public function __invoke(Request $request, LiveView $live): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $data = $request->validate([
            'samples' => ['required', 'array', 'max:30'],
            'samples.*.at' => ['required', 'date'],
            'samples.*.rx_bps' => ['required', 'integer', 'min:0'],
            'samples.*.tx_bps' => ['required', 'integer', 'min:0'],
            'samples.*.network' => ['nullable', 'in:wifi,mobile,none,other'],
            'samples.*.signal_dbm' => ['nullable', 'integer', 'between:-200,0'],
            'samples.*.down_kbps' => ['nullable', 'integer', 'min:0'],
            'samples.*.up_kbps' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(['continue' => $live->record($device, $data['samples'])]);
    }
}
