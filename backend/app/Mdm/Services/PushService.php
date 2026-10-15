<?php

namespace App\Mdm\Services;

use App\Mdm\Models\Device;
use Illuminate\Support\Facades\Log;

/**
 * The only place that talks to Firebase Cloud Messaging (CLAUDE.md rule 5). Messages carry no data except
 * "check in now"; the phone then fetches policy and commands over authenticated HTTPS (ARCHITECTURE.md §8).
 *
 * Until the Firebase project exists this only logs: phones still pick up changes at their periodic check-in.
 */
class PushService
{
    public function wake(Device $device): void
    {
        if (! $device->fcm_token) {
            return;
        }

        // TODO(Milestone A, Firebase step): send an FCM HTTP v1 high-priority data message {"type":"checkin"}.
        Log::info('push.wake skipped: Firebase not configured yet', ['device' => $device->asset_tag]);
    }
}
