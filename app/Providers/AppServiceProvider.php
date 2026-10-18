<?php

namespace App\Providers;

use App\Access\Modules;
use App\Models\Contact;
use App\Models\Trade;
use App\Models\User;
use App\Support\Audit;
use App\Support\StageSettings;
use App\Support\Uploads;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bühnen-Stammdaten der Halle: einmal je Anfrage aus der Datenbank
        $this->app->scoped(StageSettings::class, fn (): StageSettings => StageSettings::load());
        // Freigegebene Module und abgeschaltete Bereiche ebenso – sie fallen bei jeder Rechteprüfung an
        $this->app->scoped(Modules::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Änderungsprotokoll: jede Änderung über Eloquent (App\Support\Audit)
        foreach ([Audit::CREATED, Audit::UPDATED, Audit::DELETED] as $action) {
            Events::listen("eloquent.{$action}: *", function (string $event, array $payload) use ($action): void {
                if (($payload[0] ?? null) instanceof Model) {
                    Audit::model($action, $payload[0]);
                }
            });
        }

        // Letzte Anmeldung (auch über „Angemeldet bleiben“) für die Sicherheitsprüfung –
        // ohne Eintrag im Änderungsprotokoll, sonst stünde jede Anmeldung darin
        Events::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        // „Passwort vergessen?“: wer sich so ein eigenes Passwort setzt, muss es nicht noch einmal ändern
        Events::listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof User && $event->user->must_change_password) {
                $event->user->forceFill(['must_change_password' => false])->save();
            }
        });

        // Zwischenablage der Uploads (config/livewire.php): kein Programm, Skript oder
        // Webseite als Inhalt, egal unter welcher Endung – als Name, weil die Regeln
        // dort in der Konfiguration stehen
        Validator::extend('upload_inhalt', fn (string $attribute, mixed $value): bool => ! Uploads::hasBlockedContent($value), Uploads::BLOCKED_MESSAGE);

        // Kurznamen statt Klassennamen in polymorphen Spalten – wie assignee_type in
        // der PHP-Version; „contact“ ersetzt seit 14.10.2026 „employee“
        Relation::enforceMorphMap([
            'contact' => Contact::class,
            'trade' => Trade::class,
            'user' => User::class,
        ]);
    }
}
