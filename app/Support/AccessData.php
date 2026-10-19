<?php

namespace App\Support;

use App\Mail\AccessDataMail;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Zugangsdaten für Konten (Verwaltung › Benutzer, seit 18.10.2026): ein
 * Passwort erzeugen und Adresse der Anmeldung, E-Mail-Adresse und Passwort per
 * Mail an die Person schicken (AccessDataMail). Das Passwort steht nur in
 * dieser Mail – das Konto speichert den Hash, das Änderungsprotokoll nur, an
 * wen die Zugangsdaten gingen.
 */
final class AccessData
{
    /** Ohne Zeichen, die sich leicht verwechseln (0/O, 1/l/I) */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    /** „Xk7m-Pq2r-Tz9w-Hb4n“: 16 Zufallszeichen in Vierergruppen (rund 93 Bit), mit Groß-, Kleinbuchstaben und Ziffern */
    public static function password(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        do {
            $chars = '';
            for ($i = 0; $i < 16; $i++) {
                $chars .= self::ALPHABET[random_int(0, $max)];
            }
        } while (preg_match('/[A-Z]/', $chars) !== 1 || preg_match('/[a-z]/', $chars) !== 1 || preg_match('/\d/', $chars) !== 1);

        return implode('-', str_split($chars, 4));
    }

    /**
     * Schickt die Zugangsdaten an das Konto. Antworten gehen an die
     * Antwortadresse der Venue, sonst an den Admin, der verschickt. False, wenn
     * die Mail nicht rausging – der Fehler landet im Log, das Konto bleibt.
     */
    public static function send(User $user, string $password, bool $isNew): bool
    {
        $sender = Auth::user();
        try {
            Mail::to(new Address((string) $user->email, $user->getFilamentName()))->send(new AccessDataMail(
                accountName: $user->getFilamentName(),
                accountEmail: (string) $user->email,
                password: $password,
                isNew: $isNew,
                replyToAddress: $sender instanceof User ? (string) $sender->email : null,
                replyToName: $sender instanceof User ? $sender->getFilamentName() : null,
            ));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        Audit::record('users', Audit::UPDATED, null, ['credentials_sent_to' => (string) $user->email],
            key: (string) $user->getKey(), label: $user->getFilamentName());

        return true;
    }
}
