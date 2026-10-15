<?php

use App\Mdm\Models\Command;
use Illuminate\Support\Facades\Schedule;

// Commands a phone never picked up within a day are expired, so the admin sees they did not happen.
Schedule::call(function () {
    Command::query()
        ->whereIn('status', ['queued', 'delivered'])
        ->where('created_at', '<', now()->subDay())
        ->update(['status' => 'expired', 'completed_at' => now()]);
})->name('mdm:expire-commands')->hourly()->withoutOverlapping();
