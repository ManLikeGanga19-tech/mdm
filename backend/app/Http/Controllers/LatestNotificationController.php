<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in account's newest unread bell notification (alerts, crew handovers). Polled every 15 s by
 * resources/views/filament/notification-chime.blade.php, which pops it up with a sound when it is new.
 */
class LatestNotificationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $latest = $user->unreadNotifications()->latest()->first();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'latest' => $latest ? [
                'id' => $latest->id,
                'title' => $latest->data['title'] ?? null,
                'body' => $latest->data['body'] ?? null,
                'status' => $latest->data['status'] ?? null,
            ] : null,
        ]);
    }
}
