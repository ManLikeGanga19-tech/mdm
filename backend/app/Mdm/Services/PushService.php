<?php

namespace App\Mdm\Services;

use App\Mdm\Jobs\SendWakePush;
use App\Mdm\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The only place that wakes phones (CLAUDE.md rule 5). A wake-up carries no data except "check in now" and an id;
 * the phone then fetches policy and commands over authenticated HTTPS (ARCHITECTURE.md §8). Two channels, same id
 * (the phone acts on whichever arrives first and ignores the other):
 * - real time: the Mercure hub built into FrankenPHP, to which each phone's agent service keeps a connection open
 *   (config/services.php 'mercure');
 * - Firebase Cloud Messaging HTTP v1, with an OAuth token minted from the service account ('fcm'), which also reaches
 *   phones whose connection is down (Doze, a network change).
 * With neither configured it does nothing: phones still pick changes up at their periodic check-in.
 */
class PushService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Real-time tokens let a phone listen to its own topic only; a fresh one is handed out at every check-in. */
    private const SUBSCRIBER_TOKEN_DAYS = 30;

    /** Queue a wake-up so the admin's click returns immediately. */
    public function wake(Device $device): void
    {
        if (($device->fcm_token && $this->configured()) || ($device->uuid && $this->realtimeConfigured())) {
            // The earliest unanswered wake-up: the next check-in records how long the phone took (checkedIn()).
            Cache::add($this->wakeKey($device), now()->getTimestampMs(), now()->addHour());
            SendWakePush::dispatch($device->id);
        }
    }

    /** @param iterable<Device> $devices */
    public function wakeMany(iterable $devices): void
    {
        foreach ($devices as $device) {
            $this->wake($device);
        }
    }

    public function configured(): bool
    {
        return filled(config('services.fcm.project_id')) && is_readable((string) config('services.fcm.credentials'));
    }

    public function realtimeConfigured(): bool
    {
        return filled(config('services.mercure.public_url'))
            && filled(config('services.mercure.publisher_key'))
            && filled(config('services.mercure.subscriber_key'));
    }

    /** Sends the wake-up now on both channels (called by the SendWakePush job). */
    public function send(Device $device): void
    {
        $id = (string) Str::uuid();
        $this->publish($device, $id);
        $this->sendFcm($device, $id);
    }

    /**
     * What the phone needs to listen for wake-ups (returned at check-in): the hub, its topic, and a token that may
     * subscribe to that topic only. Null when the real-time channel is off.
     *
     * @return array{hub_url: string, topic: string, token: string}|null
     */
    public function realtimeFor(Device $device): ?array
    {
        if (! $device->uuid || ! $this->realtimeConfigured()) {
            return null;
        }
        $topic = $this->topic($device);

        return [
            'hub_url' => config('services.mercure.public_url'),
            'topic' => $topic,
            'token' => $this->hubToken(config('services.mercure.subscriber_key'), 'subscribe', $topic, 'device:'.$device->uuid, self::SUBSCRIBER_TOKEN_DAYS * 86400),
        ];
    }

    /** Called at check-in: records how long the phone took to answer the earliest unanswered wake-up. */
    public function checkedIn(Device $device): void
    {
        $sentAt = Cache::pull($this->wakeKey($device));
        if ($sentAt !== null) {
            Cache::put('wake-latency:'.$device->id, [
                'seconds' => round((now()->getTimestampMs() - $sentAt) / 1000, 1),
                'at' => now()->toIso8601ZuluString(),
            ], now()->addDays(7));
        }
    }

    /** @return array{seconds: float, at: string}|null How long the phone took to answer its last wake-up. */
    public function wakeLatency(Device $device): ?array
    {
        return Cache::get('wake-latency:'.$device->id);
    }

    /** Real-time channel: a private update on the phone's topic. A failure is logged; FCM still goes out. */
    private function publish(Device $device, string $id): void
    {
        if (! $device->uuid || ! $this->realtimeConfigured()) {
            return;
        }
        $topic = $this->topic($device);
        try {
            Http::withToken($this->hubToken(config('services.mercure.publisher_key'), 'publish', $topic, 'server', 300))
                ->asForm()
                ->timeout(5)
                ->post(config('services.mercure.internal_url'), [
                    'topic' => $topic,
                    'private' => 'on',
                    'data' => json_encode(['type' => 'checkin', 'id' => $id]),
                ])
                ->throw();
        } catch (Throwable $e) {
            Log::warning('push: real-time publish failed', ['device' => $device->asset_tag, 'error' => $e->getMessage()]);
        }
    }

    /** Firebase channel. Clears tokens FCM says are dead; other failures throw so the job retries. */
    private function sendFcm(Device $device, string $id): void
    {
        if (! $device->fcm_token || ! $this->configured()) {
            return;
        }

        $response = Http::withToken($this->accessToken())
            ->timeout(10)
            ->post('https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send', [
                'message' => [
                    'token' => $device->fcm_token,
                    'data' => ['type' => 'checkin', 'id' => $id],
                    'android' => ['priority' => 'HIGH', 'ttl' => '300s'],
                ],
            ]);

        if ($response->successful()) {
            return;
        }
        $status = $response->json('error.status');
        if ($response->status() === 404 || $status === 'UNREGISTERED' || $status === 'INVALID_ARGUMENT') {
            // The app was reinstalled or its token rotated; the phone sends a fresh one at its next check-in.
            $device->forceFill(['fcm_token' => null])->save();
            Log::info('push: dropped dead FCM token', ['device' => $device->asset_tag, 'status' => $status]);

            return;
        }
        throw new RuntimeException("FCM send failed ({$response->status()} {$status})"); // job retries
    }

    /** OAuth access token for FCM, from a self-signed service-account JWT; cached until shortly before expiry. */
    private function accessToken(): string
    {
        return Cache::remember('fcm:access-token', now()->addMinutes(50), function () {
            $account = json_decode((string) file_get_contents(config('services.fcm.credentials')), true);
            $now = time();
            $header = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->b64(json_encode([
                'iss' => $account['client_email'],
                'scope' => self::SCOPE,
                'aud' => $account['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            if (! openssl_sign("{$header}.{$claims}", $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Could not sign the FCM service-account JWT.');
            }

            $response = Http::asForm()->timeout(10)->post($account['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$header}.{$claims}.".$this->b64($signature),
            ])->throw();

            return $response->json('access_token');
        });
    }

    private function topic(Device $device): string
    {
        return rtrim((string) config('app.url'), '/').'/devices/'.$device->uuid;
    }

    /**
     * A Mercure access token granting $action on exactly one topic. The hub in this FrankenPHP version follows the
     * current Mercure spec: RFC 9068 "at+jwt" with iss/aud/exp/sub/client_id/iat/jti, and the permission in
     * authorization_details (checked against the hub on 2026-10-16).
     */
    private function hubToken(string $key, string $action, string $topic, string $subject, int $ttlSeconds): string
    {
        $now = time();
        $header = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'at+jwt']));
        $claims = $this->b64(json_encode([
            'iss' => config('services.mercure.issuer'),
            'aud' => config('services.mercure.public_url'),
            'sub' => $subject,
            'client_id' => 'noahport-mdm',
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => (string) Str::uuid(),
            'authorization_details' => [[
                'type' => 'https://mercure.rocks/authorization-detail',
                'actions' => [$action],
                'topics' => [['match' => $topic]],
            ]],
        ], JSON_UNESCAPED_SLASHES));

        return "{$header}.{$claims}.".$this->b64(hash_hmac('sha256', "{$header}.{$claims}", $key, true));
    }

    private function wakeKey(Device $device): string
    {
        return 'wake-sent:'.$device->id;
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
