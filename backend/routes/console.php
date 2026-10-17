<?php

use App\Mdm\Models\Alert;
use App\Mdm\Models\Command;
use App\Mdm\Models\Device;
use App\Mdm\Models\IngestBatch;
use App\Mdm\Models\NetworkLog;
use App\Mdm\Models\Setting;
use App\Mdm\Models\StatusReport;
use App\Mdm\Services\AlertService;
use App\Mdm\Services\HandoverEnforcement;
use App\Mdm\Services\PushService;
use Illuminate\Support\Facades\Schedule;

// Commands a phone never picked up within a day are expired, so the admin sees they did not happen.
Schedule::call(function () {
    Command::query()
        ->whereIn('status', ['queued', 'delivered'])
        ->where('created_at', '<', now()->subDay())
        ->update(['status' => 'expired', 'completed_at' => now()]);
})->name('mdm:expire-commands')->hourly()->withoutOverlapping();

// Retention (Kenya Data Protection Act; ARCHITECTURE.md §6): network logs, status history, raw uploads and alerts are
// kept for the configured number of days (default 90). Hourly usage totals are small and kept for reporting.
Schedule::call(function () {
    $cutoff = now()->subDays((int) Setting::get('retention_days'));
    NetworkLog::query()->where('occurred_at', '<', $cutoff)->delete();
    StatusReport::query()->where('reported_at', '<', $cutoff)->delete();
    IngestBatch::query()->where('received_at', '<', $cutoff)->delete();
    Alert::query()->where('triggered_at', '<', $cutoff)->delete();
})->name('mdm:prune-telemetry')->dailyAt('01:15')->withoutOverlapping();

// Handover lock: right after a handover window closes, wake every phone so those not handed over lock within
// seconds (phones that miss the push still lock at their next check-in).
Schedule::call(function () {
    if (app(HandoverEnforcement::class)->windowJustClosed(now())) {
        app(PushService::class)->wakeMany(Device::query()->where('state', Device::STATE_ENROLLED)->get());
    }
})->name('mdm:handover-lock')->everyMinute()->withoutOverlapping();

// Alert rules (Alerts page): new alerts are emailed to each rule's recipients and shown in the admin site's bell.
Schedule::call(fn () => app(AlertService::class)->check())
    ->name('mdm:check-alerts')->everyFiveMinutes()->withoutOverlapping();
