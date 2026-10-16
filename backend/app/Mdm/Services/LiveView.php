<?php

namespace App\Mdm\Services;

use App\Mdm\Models\AuditLog;
use App\Mdm\Models\Device;
use Illuminate\Support\Facades\Cache;

/**
 * Live view: for a few minutes the phone streams its current connection (speed, network, signal) every ~2 seconds.
 * Short-lived data, so it lives in the cache (Redis), not the database. The phone keeps sending while the session
 * is open; Stop or the time limit closes it.
 */
class LiveView
{
    public const DEFAULT_SECONDS = 600;

    private const KEEP_SAMPLES = 180; // ~6 minutes at one sample every 2 s

    public function __construct(private CommandService $commands) {}

    public function start(Device $device, int $seconds = self::DEFAULT_SECONDS): void
    {
        Cache::put($this->untilKey($device), now()->addSeconds($seconds)->timestamp, $seconds + 60);
        $this->commands->send($device, 'live_view', ['seconds' => $seconds]);
        AuditLog::record('live_view.started', $device, ['asset_tag' => $device->asset_tag, 'seconds' => $seconds]);
    }

    public function stop(Device $device): void
    {
        Cache::forget($this->untilKey($device));
        AuditLog::record('live_view.stopped', $device, ['asset_tag' => $device->asset_tag]);
    }

    public function isActive(Device $device): bool
    {
        return (int) Cache::get($this->untilKey($device), 0) > now()->timestamp;
    }

    /** Seconds left in the session, or 0. */
    public function remaining(Device $device): int
    {
        return max(0, (int) Cache::get($this->untilKey($device), 0) - now()->timestamp);
    }

    /** Stores samples from the phone; returns whether it should keep sending. */
    public function record(Device $device, array $samples): bool
    {
        $all = array_slice(array_merge($this->samples($device), $samples), -self::KEEP_SAMPLES);
        Cache::put($this->samplesKey($device), $all, now()->addMinutes(15));

        return $this->isActive($device);
    }

    /** @return list<array{at: string, rx_bps: int, tx_bps: int, network: ?string, signal_dbm: ?int, down_kbps: ?int, up_kbps: ?int}> */
    public function samples(Device $device): array
    {
        return Cache::get($this->samplesKey($device), []);
    }

    private function untilKey(Device $device): string
    {
        return "live:{$device->id}:until";
    }

    private function samplesKey(Device $device): string
    {
        return "live:{$device->id}:samples";
    }
}
