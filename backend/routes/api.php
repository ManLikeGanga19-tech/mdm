<?php

use App\Mdm\Http\Controllers\Device\CheckinController;
use App\Mdm\Http\Controllers\Device\CommandResultController;
use App\Mdm\Http\Controllers\Device\EnrollController;
use App\Mdm\Http\Controllers\Device\EnrollmentCodeController;
use App\Mdm\Http\Controllers\Device\IngestController;
use App\Mdm\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

// Device API used by the agent on the phones (ARCHITECTURE.md §7). Prefix: /api.
Route::prefix('device/v1')->middleware('throttle:device')->group(function () {
    Route::post('enroll', EnrollController::class);
    Route::post('enrollment-code/check', EnrollmentCodeController::class);

    Route::middleware(AuthenticateDevice::class)->group(function () {
        Route::post('checkin', CheckinController::class);
        Route::post('commands/{uuid}/result', CommandResultController::class);
        Route::post('usage', IngestController::class)->defaults('kind', 'usage');
        Route::post('network-logs', IngestController::class)->defaults('kind', 'network_logs');
        Route::post('inventory', IngestController::class)->defaults('kind', 'inventory');
    });
});
