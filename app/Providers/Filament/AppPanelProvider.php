<?php

namespace App\Providers\Filament;

use App\Models\Setting;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Der interne Arbeitsbereich. Er ist die App selbst und liegt deshalb ohne
 * Pfadpräfix unter / – Anmeldung unter /login wie in der PHP-Version.
 *
 * Navigation oben und keine Breitenbegrenzung: Die Listen brauchen die ganze
 * Seitenbreite.
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('')
            ->login()
            // Jede Seite braucht eine vollständige Policy – fehlt eine Methode, bricht
            // Filament ab, statt die Aktion stillschweigend zu erlauben.
            ->strictAuthorization()
            // Mit dem Hallennamen – jede Halle hat ihre eigene Installation
            ->brandName(fn (): string => collect([config('app.name'), Setting::lookup(Setting::VENUE_NAME)])
                ->filter()
                ->implode(' · '))
            ->colors([
                'primary' => Color::Blue,
            ])
            ->spa()
            ->topNavigation()
            ->maxContentWidth(Width::Full)
            // Eigene Stile ohne Build-Schritt (Event-Liste, Workspace), Version = Änderungszeit
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => sprintf(
                '<link rel="stylesheet" href="%s?v=%d">',
                asset('css/vcontrol.css'),
                @filemtime(public_path('css/vcontrol.css')) ?: 0,
            ))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
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
