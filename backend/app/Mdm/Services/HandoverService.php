<?php

namespace App\Mdm\Services;

use App\Mdm\Models\AuditLog;
use App\Mdm\Models\Clerk;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shift handovers: who holds which shared phone, and since when. One open assignment per phone and per clerk
 * (enforced by partial unique indexes); every change is audited.
 */
class HandoverService
{
    /** Gives $device to $clerk now. Closes the phone's previous holder and the clerk's previous phone. */
    public function handOver(Device $device, Clerk $clerk, ?string $note = null, ?Carbon $at = null): DeviceAssignment
    {
        $at ??= now();

        return DB::transaction(function () use ($device, $clerk, $note, $at) {
            $open = DeviceAssignment::query()
                ->whereNull('ended_at')
                ->where(fn ($q) => $q->where('device_id', $device->id)->orWhere('clerk_id', $clerk->id))
                ->lockForUpdate()
                ->get();

            $current = $open->firstWhere('device_id', $device->id);
            if ($current && $current->clerk_id === $clerk->id) {
                return $current; // already holding it
            }

            foreach ($open as $assignment) {
                $assignment->update(['ended_at' => $at, 'ended_by' => auth()->id()]);
            }

            $assignment = DeviceAssignment::create([
                'device_id' => $device->id,
                'clerk_id' => $clerk->id,
                'started_at' => $at,
                'assigned_by' => auth()->id(),
                'note' => $note,
            ]);

            AuditLog::record('handover', $device, [
                'asset_tag' => $device->asset_tag,
                'from' => $current?->clerk?->label(),
                'to' => $clerk->label(),
                'note' => $note,
            ]);

            return $assignment;
        });
    }

    /** The clerk returns the phone and nobody holds it (e.g. end of the last shift). */
    public function returnPhone(Device $device, ?string $note = null): void
    {
        DB::transaction(function () use ($device, $note) {
            $current = $device->currentAssignment()->lockForUpdate()->first();
            if (! $current) {
                return;
            }
            $current->update(['ended_at' => now(), 'ended_by' => auth()->id(), 'note' => $note ?? $current->note]);
            AuditLog::record('phone_returned', $device, ['asset_tag' => $device->asset_tag, 'from' => $current->clerk->label()]);
        });
    }

    /** Who held $device at $at (for attributing usage and network activity). */
    public function holderAt(Device $device, Carbon $at): ?Clerk
    {
        return DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->where('started_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))
            ->first()?->clerk;
    }
}
