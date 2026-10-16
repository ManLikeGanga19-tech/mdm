<?php

namespace App\Mdm\Services;

use App\Mdm\Models\AuditLog;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceCredential;
use App\Mdm\Models\DeviceGroup;
use App\Mdm\Models\EnrollmentToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enrollment codes (created on the admin site, typed into NoahportEnroll.exe) and device enrollment.
 * Codes and device credentials are random 32+ byte secrets stored only as SHA-256 hashes (ARCHITECTURE.md §8).
 */
class EnrollmentService
{
    /** @return array{0: string, 1: EnrollmentToken} the plaintext code (shown once) and its record */
    public function issueCode(string $label, ?DeviceGroup $group, int $maxUses, int $validDays): array
    {
        // Grouped for typing: NPE-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX (24 letters/digits, about 124 bits).
        $code = 'NPE-'.implode('-', str_split(strtoupper(Str::random(24)), 4));
        $token = EnrollmentToken::create([
            'label' => $label,
            'token_hash' => self::hash($code),
            'group_id' => $group?->id,
            'max_uses' => $maxUses,
            'expires_at' => now()->addDays($validDays),
            'created_by' => auth()->id(),
        ]);
        AuditLog::record('enrollment_code.created', $token, ['label' => $label, 'max_uses' => $maxUses, 'valid_days' => $validDays]);

        return [$code, $token];
    }

    public function findUsableCode(string $code): ?EnrollmentToken
    {
        $token = EnrollmentToken::query()->where('token_hash', self::hash(self::normalize($code)))->first();

        return $token && $token->expires_at->isFuture() && $token->uses < $token->max_uses ? $token : null;
    }

    /**
     * Registers (or re-registers, after a reset) a phone. Matches a pre-registered phone by asset tag, or a known
     * phone by serial; revokes any older credentials. Returns the phone and its new plaintext credential.
     *
     * @return array{0: Device, 1: string}
     */
    public function enroll(EnrollmentToken $token, array $info): array
    {
        return DB::transaction(function () use ($token, $info) {
            $token = EnrollmentToken::query()->lockForUpdate()->findOrFail($token->id);
            abort_unless($token->uses < $token->max_uses && $token->expires_at->isFuture(), 401, 'Enrollment code used up or expired.');

            $device = Device::query()->where('asset_tag', $info['asset_tag'])->first()
                ?? (filled($info['serial'] ?? null) ? Device::query()->where('serial', $info['serial'])->first() : null)
                ?? new Device(['asset_tag' => $info['asset_tag']]);

            $device->fill([
                'asset_tag' => $info['asset_tag'],
                'serial' => $info['serial'] ?? $device->serial,
                'imei' => $info['imei'] ?? $device->imei,
                'manufacturer' => $info['manufacturer'] ?? null,
                'model' => $info['model'] ?? null,
                'android_version' => $info['android_version'] ?? null,
                'security_patch' => $info['security_patch'] ?? null,
                'agent_version_code' => $info['agent_version_code'] ?? null,
                'agent_version_name' => $info['agent_version_name'] ?? null,
                'group_id' => $device->group_id ?? $token->group_id,
                'state' => Device::STATE_ENROLLED,
                'enrolled_at' => now(),
                'applied_policy_version' => null,
            ])->save();

            $device->credentials()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $credential = Str::random(48);
            DeviceCredential::create(['device_id' => $device->id, 'token_hash' => self::hash($credential), 'created_at' => now()]);

            $token->increment('uses');
            AuditLog::record('device.enrolled', $device, [
                'asset_tag' => $device->asset_tag, 'serial' => $device->serial, 'code' => $token->label,
            ]);

            return [$device, $credential];
        });
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** Codes are typed by people: ignore case and stray spaces. */
    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', $code));
    }
}
