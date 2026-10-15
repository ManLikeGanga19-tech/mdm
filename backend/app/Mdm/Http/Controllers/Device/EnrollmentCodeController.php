<?php

namespace App\Mdm\Http\Controllers\Device;

use App\Mdm\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/device/v1/enrollment-code/check — lets NoahportEnroll.exe confirm a code before touching any phone.
 * Does not consume a use. Rate-limited like the rest of the device API.
 */
class EnrollmentCodeController
{
    public function __invoke(Request $request, EnrollmentService $enrollment): JsonResponse
    {
        $data = $request->validate(['enrollment_token' => ['required', 'string', 'max:100']]);
        $token = $enrollment->findUsableCode($data['enrollment_token']);
        if (! $token) {
            return response()->json(['message' => 'Enrollment code is wrong, used up or expired.'], 401);
        }

        return response()->json([
            'label' => $token->label,
            'group' => $token->group?->name,
            'uses_left' => $token->max_uses - $token->uses,
            'expires_at' => $token->expires_at->toIso8601ZuluString(),
        ]);
    }
}
