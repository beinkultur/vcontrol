<?php

namespace App\Support;

use App\Mail\DaysheetMail;
use App\Models\Daysheet;
use App\Models\Event;
use App\Models\Setting;
use App\Models\Trade;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Daysheets versenden: kurz vor der Veranstaltung ein Link auf die Event-Infos
 * für Externe (App\Support\ExternSheet), gültig ab Versand bis zum Ende des Tages
 * nach der Veranstaltung (validUntil). Vorbelegt wird mit den
 * Standards der Halle (Verwaltung › Halle) und den E-Mail-Adressen der
 * beteiligten Gewerke im BCC.
 *
 * Gespeichert werden Betreff und Text mit dem Platzhalter {link} – der
 * Schlüssel steht nur in der verschickten Mail, nie in Datenbank oder Audit.
 */
final class Daysheets
{
    public const DEFAULT_SUBJECT = 'Daysheet {event} – {datum}';

    public const DEFAULT_TEXT = "Hallo,\n\nhier ist das Daysheet für {event} am {datum} in der {halle}:\n{link}\n\nDer Link ist bis {gueltig_bis} gültig. Auf der Seite lassen sich die Infos drucken oder als PDF speichern.\n\nViele Grüße\n{halle}";

    public const PLACEHOLDER_HELP = '{event}, {datum} und {halle} werden ersetzt, beim Versand auch {link} und {gueltig_bis}. Fehlt {link}, steht der Link am Ende.';

    /**
     * Vorbelegung des Versand-Dialogs.
     *
     * @return array{to: list<string>, bcc: list<string>, subject: string, body: string}
     */
    public static function defaults(Event $event): array
    {
        return [
            'to' => self::emails(explode(',', (string) Setting::lookup(Setting::DAYSHEET_TO))),
            'bcc' => self::tradeEmails($event),
            'subject' => self::fill(Setting::lookup(Setting::DAYSHEET_SUBJECT) ?: self::DEFAULT_SUBJECT, $event),
            'body' => self::fill(Setting::lookup(Setting::DAYSHEET_TEXT) ?: self::DEFAULT_TEXT, $event),
        ];
    }

    /**
     * E-Mail-Adressen der beteiligten Gewerke: aus den Gewerken (Leistungen) und
     * dem Personal des Events.
     *
     * @return list<string>
     */
    public static function tradeEmails(Event $event): array
    {
        $tradeIds = $event->services()->whereNotNull('trade_id')->pluck('trade_id')
            ->merge($event->assignments()->where('assignee_type', 'trade')->pluck('assignee_id'))
            ->unique();

        return self::emails(Trade::query()->whereIn('id', $tradeIds)->pluck('email')->all());
    }

    /**
     * Bis wann ein jetzt verschickter Link gilt: bis 23:59 Uhr am Tag nach dem
     * letzten Veranstaltungstag (Vorgabe vom 02.10.2026). Ohne Datum: null.
     */
    public static function validUntil(Event $event): ?CarbonInterface
    {
        if ($event->starts_at === null) {
            return null;
        }

        return self::lastDay($event)->addDay()->endOfDay();
    }

    /**
     * Letzter Veranstaltungstag. Das Ende ist exklusiv gespeichert (00:00 des
     * Folgetags, so der Import) oder liegt nach Mitternacht – beides zählt zum
     * Vortag. Ein Ende ab 6 Uhr ist ein echter letzter Tag.
     */
    private static function lastDay(Event $event): CarbonInterface
    {
        $start = $event->starts_at->copy()->startOfDay();
        $end = $event->ends_at;
        if ($end === null || $end->lessThanOrEqualTo($start)) {
            return $start;
        }
        $day = $end->hour < 6 ? $end->copy()->subDay()->startOfDay() : $end->copy()->startOfDay();

        return $day->greaterThan($start) ? $day : $start;
    }

    /**
     * Platzhalter ersetzen; {link} und {gueltig_bis} erst beim Versand.
     */
    public static function fill(string $text, Event $event, ?string $link = null, ?CarbonInterface $validUntil = null): string
    {
        $replace = [
            '{event}' => (string) $event->title,
            '{datum}' => $event->starts_at ? EventDisplay::weekday($event->starts_at) . ', ' . $event->starts_at->format('d.m.Y') : 'Datum offen',
            '{halle}' => Setting::lookup(Setting::VENUE_NAME) ?: (string) config('app.name'),
        ];
        if ($link !== null) {
            $replace['{link}'] = $link;
        }
        if ($validUntil !== null) {
            $replace['{gueltig_bis}'] = $validUntil->format('d.m.Y, H:i') . ' Uhr';
        }

        return strtr($text, $replace);
    }

    /**
     * Daysheet anlegen und verschicken: eine Mail an „An“, die übrigen im BCC.
     * Scheitert der Versand, wird auch nichts gespeichert.
     *
     * @param  list<string>  $to
     * @param  list<string>  $bcc
     */
    public static function send(Event $event, array $to, array $bcc, string $subject, string $body, ?User $sender = null): Daysheet
    {
        $to = self::emails($to);
        $bcc = array_values(array_diff(self::emails($bcc), $to));
        if ($to === []) {
            throw new InvalidArgumentException('Ein Daysheet braucht mindestens einen Empfänger unter „An“.');
        }
        if (!str_contains($body, '{link}')) {
            $body = rtrim($body) . "\n\n{link}";
        }
        // Gespeichert wie verschickt, nur Link und Ablauf bleiben Platzhalter
        $subject = self::fill(trim($subject), $event);
        $body = self::fill(str_replace(["\r\n", "\r"], "\n", $body), $event);

        $validUntil = self::validUntil($event);
        if ($validUntil === null) {
            throw new InvalidArgumentException('Das Event hat noch kein Datum – ohne Datum gibt es kein Daysheet.');
        }
        if ($validUntil->isPast()) {
            throw new InvalidArgumentException('Die Veranstaltung ist vorbei – ein neuer Link wäre schon abgelaufen.');
        }
        $token = Daysheet::newToken();
        $link = route('daysheet.show', $token);

        return DB::transaction(function () use ($event, $to, $bcc, $subject, $body, $sender, $token, $validUntil, $link): Daysheet {
            $daysheet = $event->daysheets()->create([
                'token_hash' => Daysheet::hashToken($token),
                'recipients_to' => $to,
                'recipients_bcc' => $bcc,
                'subject' => $subject,
                'body' => $body,
                'expires_at' => $validUntil,
            ]);

            Mail::to($to)->bcc($bcc)->send(new DaysheetMail(
                subjectLine: self::fill($subject, $event, $link, $validUntil),
                text: self::fill($body, $event, $link, $validUntil),
                replyToAddress: $sender?->email,
                replyToName: $sender?->getFilamentName(),
            ));

            return $daysheet;
        });
    }

    /**
     * Gültige, kleingeschriebene Adressen ohne Doppelte.
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    public static function emails(array $values): array
    {
        return collect($values)
            ->map(fn (mixed $value): string => mb_strtolower(trim((string) $value)))
            ->filter(fn (string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
