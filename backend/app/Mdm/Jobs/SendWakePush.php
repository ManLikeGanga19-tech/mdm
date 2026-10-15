<?php

namespace App\Mdm\Jobs;

use App\Mdm\Models\Device;
use App\Mdm\Services\PushService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends one "check in now" push to a phone. Unique per phone for a few seconds: bursts of clicks send one push. */
class SendWakePush implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 30];

    public int $uniqueFor = 5;

    public function __construct(public int $deviceId) {}

    public function uniqueId(): string
    {
        return (string) $this->deviceId;
    }

    public function handle(PushService $push): void
    {
        if ($device = Device::find($this->deviceId)) {
            $push->send($device);
        }
    }
}
