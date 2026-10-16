<?php

namespace App\Mdm\Services;

use App\Mdm\Models\AuditLog;
use App\Mdm\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Live view: for a few minutes the phone streams its current connection (speed, network, signal, the apps using the
 * most data) every ~2 seconds, plus its event lines (commands, rules, handover lock, network/charger changes). The
 * server adds its own lines (handovers, commands sent, wake-ups). Short-lived data, so it lives in the cache (Redis),
 * not the database. The phone keeps sending while the session is open; Stop or the time limit closes it.
 */
class LiveView
{
    public const DEFAULT_SECONDS = 600;

    private const KEEP_SAMPLES = 180; // ~6 minutes at one sample every 2 s

    private const KEEP_EVENTS = 300;

    public function __construct(private CommandService $commands) {}

    public function start(Device $device, int $seconds = self::DEFAULT_SECONDS): void
    {
        Cache::put($this->untilKey($device), now()->addSeconds($seconds)->timestamp, $seconds + 60);
        $this->commands->send($device, 'live_view', ['seconds' => $seconds]);
        AuditLog::record('live_view.started', $device, ['asset_tag' => $device->asset_tag, 'seconds' => $seconds]);
    }

    public function stop(Device $device): void
    {
        $this->event($device, 'Live view stopped', 'server');
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

    /**
     * Stores samples and event lines from the phone; returns whether it should keep sending.
     *
     * @param  list<array{at: string, text: string}>  $events
     */
    public function record(Device $device, array $samples, array $events = []): bool
    {
        if ($samples) {
            $all = array_slice(array_merge($this->samples($device), $samples), -self::KEEP_SAMPLES);
            Cache::put($this->samplesKey($device), $all, now()->addMinutes(15));
        }
        foreach ($events as $event) {
            $this->push($device, ['at' => $event['at'], 'source' => 'phone', 'text' => $event['text']]);
        }

        return $this->isActive($device);
    }

    /** A server-side line for the console (handover, command sent, wake-up...), kept only while a session runs. */
    public function event(Device $device, string $text, string $source = 'server'): void
    {
        if ($this->isActive($device)) {
            $this->push($device, ['at' => now()->toIso8601ZuluString(), 'source' => $source, 'text' => $text]);
        }
    }

    /** @return list<array{at: string, rx_bps: int, tx_bps: int, network: ?string, signal_dbm: ?int, down_kbps: ?int, up_kbps: ?int, apps?: list<array{package: string, label: string, rx_bps: int, tx_bps: int}>, apps_age_s?: int}> */
    public function samples(Device $device): array
    {
        return Cache::get($this->samplesKey($device), []);
    }

    /**
     * The console: one line per sample and per event, oldest first.
     *
     * @return list<array{at: Carbon, kind: string, text: string}>
     */
    public function lines(Device $device, int $limit = 200): array
    {
        $lines = [];
        foreach ($this->samples($device) as $s) {
            $lines[] = ['at' => Carbon::parse($s['at']), 'kind' => 'speed', 'text' => $this->describe($s)];
        }
        foreach (Cache::get($this->eventsKey($device), []) as $e) {
            $kind = $e['source'] === 'phone' ? (str_starts_with($e['text'], 'FAIL') ? 'error' : 'phone') : 'server';
            $lines[] = ['at' => Carbon::parse($e['at']), 'kind' => $kind, 'text' => $e['text']];
        }
        usort($lines, fn ($a, $b) => $a['at'] <=> $b['at']);

        return array_slice($lines, -$limit);
    }

    /** "↓ 4.2 Mbps ↑ 310 kbps · Mobile data -87 dBm · Chrome ↓3.1 Mbps, WhatsApp ↓600 kbps" */
    private function describe(array $s): string
    {
        $network = match ($s['network'] ?? null) {
            'wifi' => 'Wi-Fi', 'mobile' => 'Mobile data', 'none' => 'Offline', default => 'Other network',
        };
        $text = sprintf('↓ %s ↑ %s · %s', self::speed($s['rx_bps']), self::speed($s['tx_bps']), $network)
            .(isset($s['signal_dbm']) ? " {$s['signal_dbm']} dBm" : '');
        if (! array_key_exists('apps', $s)) {
            return $text;
        }
        $apps = collect($s['apps'])->map(fn ($a) => $a['label'].' ↓'.self::speed($a['rx_bps']).($a['tx_bps'] >= 100_000 ? ' ↑'.self::speed($a['tx_bps']) : ''));
        $age = (int) ($s['apps_age_s'] ?? 0);

        return $text.' · '.($apps->isEmpty() ? 'no app traffic' : $apps->implode(', ')).($age > 6 ? " (apps {$age}s ago)" : '');
    }

    public static function speed(int $bps): string
    {
        return match (true) {
            $bps >= 1_000_000 => round($bps / 1e6, 1).' Mbps',
            $bps >= 1_000 => round($bps / 1e3).' kbps',
            default => $bps.' bps',
        };
    }

    private function push(Device $device, array $event): void
    {
        $all = Cache::get($this->eventsKey($device), []);
        $all[] = $event;
        Cache::put($this->eventsKey($device), array_slice($all, -self::KEEP_EVENTS), now()->addMinutes(15));
    }

    private function untilKey(Device $device): string
    {
        return "live:{$device->id}:until";
    }

    private function samplesKey(Device $device): string
    {
        return "live:{$device->id}:samples";
    }

    private function eventsKey(Device $device): string
    {
        return "live:{$device->id}:events";
    }
}
