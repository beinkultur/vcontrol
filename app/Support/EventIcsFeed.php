<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Setting;

/**
 * ICS-Feed aller bestätigten Veranstaltungen zum Abonnieren in Kalender-Apps,
 * wie in der PHP-Version (EventIcsFeed). Kalender-Apps schicken keine Session
 * mit, deshalb hängt der Zugriff an einem geheimen Schlüssel (Einstellung der
 * Halle). Ohne Schlüssel ist der Feed aus – nie versehentlich öffentlich.
 *
 * Alle Events sind ganztägig. DTEND ist exklusiv (eintägiges Event = Folgetag);
 * fehlt das Ende oder liegt es nicht nach dem Beginn, gilt Beginn + 1 Tag.
 */
final class EventIcsFeed
{
    private const EOL = "\r\n";

    public static function token(): ?string
    {
        $token = trim((string) Setting::lookup(Setting::CALENDAR_FEED_TOKEN));

        return $token === '' ? null : $token;
    }

    public static function isEnabled(): bool
    {
        return self::token() !== null;
    }

    /** Vergleich in konstanter Zeit; ohne Schlüssel passt nichts. */
    public static function tokenMatches(?string $given): bool
    {
        $expected = self::token();

        return $expected !== null && is_string($given) && $given !== '' && hash_equals($expected, $given);
    }

    public static function url(): ?string
    {
        return self::isEnabled() ? route('calendar.feed', ['token' => self::token()]) : null;
    }

    public static function build(): string
    {
        $venue = Setting::lookup(Setting::VENUE_NAME) ?: (string) config('app.name');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//VenueControl//Events//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escape($venue . ' — Veranstaltungen'),
            'X-WR-TIMEZONE:' . config('app.timezone'),
        ];

        $stamp = gmdate('Ymd\THis\Z');
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'vcontrol.eu';
        $events = Event::query()
            ->with('promoter')
            ->where('status', 'bestätigt')
            ->whereNotNull('starts_at')
            ->orderBy('starts_at')
            ->get();
        foreach ($events as $event) {
            array_push($lines, ...self::event($event, $stamp, $host));
        }
        $lines[] = 'END:VCALENDAR';

        return implode('', array_map(fn (string $line): string => self::fold($line) . self::EOL, $lines));
    }

    /** @return list<string> */
    private static function event(Event $event, string $stamp, string $host): array
    {
        $start = $event->starts_at->format('Y-m-d');
        $end = $event->ends_at?->format('Y-m-d');
        if ($end === null || $end <= $start) {
            $end = $event->starts_at->copy()->addDay()->format('Y-m-d');
        }

        $description = array_filter([
            filled($event->promoter?->name) ? 'Veranstalter: ' . $event->promoter->name : null,
            ($category = trim(trim((string) $event->event_type1) . ' / ' . trim((string) $event->event_type2), ' /')) !== '' ? 'Kategorie: ' . $category : null,
            filled($event->va_id) ? 'VA-ID: ' . $event->va_id : null,
        ]);

        return array_values(array_filter([
            'BEGIN:VEVENT',
            'UID:event-' . $event->id . '@' . $host,
            'DTSTAMP:' . $stamp,
            'DTSTART;VALUE=DATE:' . str_replace('-', '', $start),
            'DTEND;VALUE=DATE:' . str_replace('-', '', $end),
            'SUMMARY:' . self::escape((string) ($event->title ?: 'Veranstaltung')),
            'TRANSP:TRANSPARENT',
            $description === [] ? null : 'DESCRIPTION:' . self::escape(implode("\n", $description)),
            'END:VEVENT',
        ]));
    }

    /** Sonderzeichen nach RFC 5545 maskieren. */
    private static function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], str_replace("\r\n", "\n", $value));
    }

    /** Zeilen auf 75 Oktett falten; nach Zeichen schneiden, damit kein UTF-8 zerrissen wird. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $limit = $out === '' ? 75 : 74;
            if (strlen($current) + strlen($char) > $limit) {
                $out .= ($out === '' ? '' : self::EOL . ' ') . $current;
                $current = '';
            }
            $current .= $char;
        }

        return $current === '' ? $out : $out . ($out === '' ? '' : self::EOL . ' ') . $current;
    }
}
