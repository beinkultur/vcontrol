<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Timebox;

/**
 * „Passwort vergessen?“ wie bei Filament, aber ohne zu verraten, ob es ein
 * Konto mit der Adresse gibt. Filament meldete sonst „Wir können keinen
 * Benutzer mit dieser E-Mail-Adresse finden“ – so ließe sich ausprobieren, wer
 * hier ein Konto hat. Jetzt bekommt jede Adresse dieselbe Antwort und dasselbe
 * leere Formular, und die Anfrage dauert immer gleich lang (Mailversand).
 * Gesperrte Konten bekommen keine Mail: Filament prüft canAccessPanel().
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        (new Timebox)->call(function (): void {
            parent::request();
        }, 1_500_000);

        $this->form->fill();
    }

    protected function getFailureNotification(string $status): ?Notification
    {
        return in_array($status, [Password::INVALID_USER, Password::RESET_THROTTLED], true)
            ? $this->getSentNotification(Password::RESET_LINK_SENT)
            : parent::getFailureNotification($status);
    }
}
