<?php

namespace App\Mdm\Http\Controllers\Device;

use App\Mdm\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/device/v1/enroll — the agent registers with the enrollment code it got from NoahportEnroll.exe and
 * receives its own credential. GET-free and idempotent per code use: each call consumes one use of the code.
 */
class EnrollController
{
    public function __invoke(Request $request, EnrollmentService $enrollment): JsonResponse
    {
        $data = $request->validate([
            'enrollment_token' => ['required', 'string', 'max:100'],
            'asset_tag' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9 ._\/-]+$/'],
            'serial' => ['nullable', 'string', 'max:64'],
            'imei' => ['nullable', 'string', 'max:32'],
            'manufacturer' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:64'],
            'android_version' => ['nullable', 'string', 'max:16'],
            'security_patch' => ['nullable', 'string', 'max:16'],
            'agent_version_code' => ['nullable', 'integer', 'min:0'],
            'agent_version_name' => ['nullable', 'string', 'max:32'],
        ]);

        $token = $enrollment->findUsableCode($data['enrollment_token']);
        if (! $token) {
            return response()->json(['message' => 'Enrollment code is wrong, used up or expired.'], 401);
        }

        [$device, $credential] = $enrollment->enroll($token, $data);

        return response()->json([
            'device_uuid' => $device->uuid,
            'device_token' => $credential,
            'checkin_interval_seconds' => CheckinController::INTERVAL_SECONDS,
        ], 201);
    }
}
