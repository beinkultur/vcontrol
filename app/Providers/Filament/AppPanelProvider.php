<?php

namespace App\Providers\Filament;

use App\Enums\ColorScheme;
use App\Filament\Pages\Auth\ChangePassword;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Schemas\EventForm;
use App\Http\Middleware\RequirePasswordChange;
use App\Models\Setting;
use App\Models\User;
use App\Support\Backups;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

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
            ->login(Login::class)
            // „Passwort vergessen?“: Link per Mail, 10 Minuten gültig (config/auth.php)
            ->passwordReset(RequestPasswordReset::class)
            // Benutzermenü › Profil: eigenes Passwort ändern (Name und E-Mail nur zur Ansicht)
            ->profile(EditProfile::class, isSimple: false)
            // Jede Seite braucht eine vollständige Policy – fehlt eine Methode, bricht
            // Filament ab, statt die Aktion stillschweigend zu erlauben.
            ->strictAuthorization()
            // Mit dem Hallennamen – jede Halle hat ihre eigene Installation. In der
            // Kopfzeile (und auf der Anmeldeseite) stehen das Logo und die Halle,
            // „VenueControl“ bleibt im Fenstertitel. Auf dem Handy nur das Logo (vcontrol.css).
            ->brandName(fn (): string => collect([config('app.name'), Setting::lookup(Setting::VENUE_NAME)])
                ->filter()
                ->implode(' · '))
            ->brandLogo(fn (): HtmlString => new HtmlString(sprintf(
                '<span class="vc-brand"><img class="vc-brand__logo" src="%s" alt="VenueControl"><span class="vc-brand__name">%s</span></span>',
                e(asset('images/logo.svg')),
                e(Setting::lookup(Setting::VENUE_NAME) ?: config('app.name')),
            )))
            ->brandLogoHeight('2rem')
            // Farbschema, das der System-Admin unter Verwaltung › System wählt
            ->colors(fn (): array => ColorScheme::current()->colors())
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('partials.icons')->render())
            ->spa()
            // Wer ein Formular mit ungespeicherten Änderungen verlässt, wird gefragt –
            // auch beim Wechsel über die Navigation (SPA). Reiterwechsel im Event bleiben
            // in derselben Seite und verlieren nichts.
            ->unsavedChangesAlerts()
            ->topNavigation()
            ->maxContentWidth(Width::Full)
            // Eigene Stile ohne Build-Schritt (Event-Liste, Workspace; sheet.css: Event-Infos
            // für Externe, auch auf der Daysheet-Seite), Version = Änderungszeit; danach
            // die Stile und eigenen Farben des Farbschemas (Kopfleiste, Phasen, Akzent)
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => collect(['css/vcontrol.css', 'css/sheet.css', ColorScheme::current()->stylesheet()])
                ->filter()
                ->map(fn (string $file): string => sprintf(
                    '<link rel="stylesheet" href="%s?v=%d">',
                    asset($file),
                    @filemtime(public_path($file)) ?: 0,
                ))
                ->push('<style>' . ColorScheme::current()->css() . '</style>')
                ->implode(''))
            // Sicherung ausgeblieben oder gescheitert: Hinweis für System-Admins oben auf jeder Seite
            ->renderHook(PanelsRenderHook::CONTENT_START, function (): string {
                $user = Auth::user();
                $warning = $user instanceof User && $user->access()->isSystemAdmin() ? Backups::warning() : null;

                return $warning === null ? '' : view('filament.system.backup-warning', ['warning' => $warning])->render();
            })
            // Event-Workspace: Speichern/Abbrechen zusätzlich in der Reiterzeile der Unterbereiche
            ->renderHook(
                EventForm::SECTION_ACTIONS_HOOK,
                fn (): string => view('filament.events.section-actions', [
                    'cancelUrl' => (($page = Livewire::current()) instanceof EditEvent ? $page->previousUrl : null) ?? EventResource::getUrl(),
                ])->render(),
                scopes: EditEvent::class,
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // Kein Dashboard: / führt zum ersten Menüpunkt, den der Benutzer sehen darf –
            // in der Regel die Event-Liste (Filament-Weiterleitung „home“).
            // Reihenfolge: Events, Buchhaltung, Codes (ohne Gruppe, nach navigationSort),
            // dann diese Gruppen.
            // Punkte ohne Gruppe stehen vorn (Events, Anfragen, Kalender), dahinter die Gruppen als Aufklapp-Menü
            ->navigationGroups(['Finanzen', 'Protokolle', 'Stammdaten', 'Verwaltung'])
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
                // Passwort vom Admin vergeben: erst ein eigenes festlegen (seit 18.10.2026)
                RequirePasswordChange::class,
            ])
            ->authenticatedRoutes(fn () => Route::get('passwort-aendern', ChangePassword::class)->name('auth.password-change'));
    }
}
