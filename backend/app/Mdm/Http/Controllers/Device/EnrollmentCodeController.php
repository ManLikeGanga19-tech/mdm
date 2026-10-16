<?php

namespace App\Mdm\Http\Controllers\Device;

use App\Mdm\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/device/v1/enrollment-code/check — lets NoahportEnroll.exe confirm a code before touching any phone, and
 * (with asset_tag + serial) whether the tag it is about to use already belongs to another phone. Does not consume a
 * use. Only answers with a valid code, and only says a tag is taken (with that phone's model and serial, which are
 * printed on the phone anyway). Rate-limited like the rest of the device API.
 */
class EnrollmentCodeController
{
    public function __invoke(Request $request, EnrollmentService $enrollment): JsonResponse
    {
        $data = $request->validate([
            'enrollment_token' => ['required', 'string', 'max:100'],
            'asset_tag' => ['nullable', 'string', 'max:40'],
            'serial' => ['nullable', 'string', 'max:64'],
        ]);
        $token = $enrollment->findUsableCode($data['enrollment_token']);
        if (! $token) {
            return response()->json(['message' => 'Enrollment code is wrong, used up or expired.'], 401);
        }
        $other = filled($data['asset_tag'] ?? null) ? $enrollment->tagConflict($data['asset_tag'], $data['serial'] ?? null) : null;

        return response()->json([
            'label' => $token->label,
            'group' => $token->group?->name,
            'uses_left' => $token->max_uses - $token->uses,
            'expires_at' => $token->expires_at->toIso8601ZuluString(),
            'tag_taken_by' => $other ? trim("{$other->manufacturer} {$other->model}").", serial {$other->serial}" : null,
        ]);
    }
}
