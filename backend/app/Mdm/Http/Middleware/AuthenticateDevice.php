<?php

namespace App\Mdm\Http\Middleware;

use App\Mdm\Models\DeviceCredential;
use App\Mdm\Services\EnrollmentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Device API auth: `Authorization: Bearer <device credential>` (hashed lookup, not revoked, phone not retired). */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken();
        $credential = $secret ? DeviceCredential::query()
            ->with('device')
            ->where('token_hash', EnrollmentService::hash($secret))
            ->whereNull('revoked_at')
            ->first() : null;

        if (! $credential || $credential->device->state === 'retired') {
            return response()->json(['message' => 'Unknown or revoked device credential.'], 401);
        }

        $request->attributes->set('device', $credential->device);

        return $next($request);
    }
}
