<?php

namespace App\Providers;

use App\Models\User;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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

        // Last sign-in, shown on the Accounts page.
        Event::listen(Login::class, fn (Login $event) => $event->user instanceof User
            ? $event->user->forceFill(['last_login_at' => now()])->saveQuietly()
            : null);

        // Device API: per phone credential (or per IP before enrollment). Check-ins every ~15 min, uploads in bursts,
        // and live view posts every few seconds while an admin watches.
        RateLimiter::for('device', fn (Request $request) => Limit::perMinute(120)
            ->by($request->bearerToken() ? 'cred:'.sha1($request->bearerToken()) : 'ip:'.$request->ip()));
    }
}
