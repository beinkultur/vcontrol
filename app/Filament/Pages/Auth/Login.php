<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Anmeldung wie bei Filament, mit einer eigenen Meldung, wenn das Passwort
 * stimmt, das Konto hier aber keinen Bereich hat (gesperrt oder ohne Rechte).
 * Sonst hieße es „Zugangsdaten nicht gefunden“, und man sucht den Fehler beim
 * Passwort. Wer das Passwort nicht kennt, sieht weiter nur die allgemeine
 * Meldung; die Prüfung läuft wie bisher in Filaments Timebox.
 */
class Login extends BaseLogin
{
    public const NO_ACCESS = 'Für dieses Konto gibt es hier keinen Zugang – bitte bei der Verwaltung der Halle melden.';

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        if (parent::isUserAllowedToAccessPanel($user)) {
            return true;
        }

        throw ValidationException::withMessages(['data.email' => self::NO_ACCESS]);
    }
}
