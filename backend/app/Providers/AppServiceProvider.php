<?php

namespace App\Providers;

use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Stored in UTC (CLAUDE.md rule 9); shown to admins in Kenyan time.
        FilamentTimezone::set(config('app.display_timezone'));

        // Device API: per phone credential (or per IP before enrollment). A phone checks in every ~15 min.
        RateLimiter::for('device', fn (Request $request) => Limit::perMinute(30)
            ->by($request->bearerToken() ? 'cred:'.sha1($request->bearerToken()) : 'ip:'.$request->ip()));
    }
}
