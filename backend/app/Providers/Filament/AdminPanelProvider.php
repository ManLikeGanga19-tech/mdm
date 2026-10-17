<?php

namespace App\Providers\Filament;

use App\Filament\Auth\RequestPasswordReset;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ShiftHandover;
use App\Http\Controllers\LatestNotificationController;
use App\Mdm\Permission;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Set-password links (invites, resets from Accounts) open Filament's reset page; "Forgot password?" asks
            // for a super admin until mail is set up (App\Filament\Auth\RequestPasswordReset).
            ->passwordReset(RequestPasswordReset::class)
            ->profile(isSimple: false) // lets every account change its own password
            // Each role starts where its work is: coordinators on the shift handover, everyone else on the overview.
            ->homeUrl(fn () => auth()->user()?->hasPermission(Permission::FleetView) || ! auth()->user()?->hasPermission(Permission::HandoverRecord)
                ? Dashboard::getUrl()
                : ShiftHandover::getUrl())
            ->brandName('Noahport MDM')
            ->favicon(asset('icons/icon-192.png'))
            // Company look: white with blue by default; users can switch to dark in the account menu.
            ->colors([
                'primary' => Color::Blue,
                'gray' => Color::Slate,
            ])
            ->defaultThemeMode(ThemeMode::Light)
            ->databaseNotifications()
            ->databaseNotificationsPolling('15s')
            // New bell notifications pop up with a chime (speaker button next to the account menu mutes it).
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.notification-chime'))
            ->authenticatedRoutes(fn () => Route::get('notifications/latest', LatestNotificationController::class)->name('notifications.latest'))
            ->sidebarCollapsibleOnDesktop()
            // Lets the admin add the site to a phone's home screen like an app.
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.pwa-head'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            // App\Filament\Pages\Dashboard (fleet overview) replaces Filament's default; no account card on it.
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
