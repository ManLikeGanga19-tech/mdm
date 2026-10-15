<?php

namespace App\Mdm\Services;

use App\Mdm\Jobs\SendWakePush;
use App\Mdm\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The only place that talks to Firebase Cloud Messaging (CLAUDE.md rule 5). Messages carry no data except
 * "check in now"; the phone then fetches policy and commands over authenticated HTTPS (ARCHITECTURE.md §8).
 * Uses the FCM HTTP v1 API with an OAuth token minted from the service account (config/services.php 'fcm').
 * Without credentials it does nothing: phones still pick changes up at their periodic check-in.
 */
class PushService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Queue a wake-up so the admin's click returns immediately. */
    public function wake(Device $device): void
    {
        if ($device->fcm_token && $this->configured()) {
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

    /** Sends the wake-up now (called by the SendWakePush job). Clears tokens FCM says are dead. */
    public function send(Device $device): void
    {
        if (! $device->fcm_token || ! $this->configured()) {
            return;
        }

        $response = Http::withToken($this->accessToken())
            ->timeout(10)
            ->post('https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send', [
                'message' => [
                    'token' => $device->fcm_token,
                    'data' => ['type' => 'checkin'],
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

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
