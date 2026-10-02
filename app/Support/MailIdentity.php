<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Mail\Mailables\Address;

/**
 * Wer Mails der Halle verschickt und wohin Antworten gehen. Absender ist die
 * Adresse aus der Server-Konfiguration (MAIL_FROM_ADDRESS, no-reply@…) mit dem
 * Namen der Halle; die Antwortadresse legt die Halle unter Verwaltung › Halle
 * fest (Setting::MAIL_REPLY_TO).
 */
final class MailIdentity
{
    public static function from(): Address
    {
        return new Address((string) config('mail.from.address'), self::venue());
    }

    /** Antwortadresse der Halle; null, solange keine (gültige) eingetragen ist. */
    public static function replyTo(): ?Address
    {
        $address = mb_strtolower(trim((string) Setting::lookup(Setting::MAIL_REPLY_TO)));

        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? new Address($address, self::venue()) : null;
    }

    private static function venue(): string
    {
        return Setting::lookup(Setting::VENUE_NAME) ?: (string) config('mail.from.name');
    }
}
