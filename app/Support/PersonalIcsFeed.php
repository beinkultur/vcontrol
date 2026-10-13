<?php

namespace App\Support;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\ExternEvents\ExternEventResource;
use App\Models\EventAssignment;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Persönlicher Kalender eines Kontos als ICS-Feed (/kalender/persoenlich.ics,
 * Adresse im Profil): die eigenen Dienste wie auf der Ebene „Persönlich“ –
 * als Konto selbst oder über das verknüpfte Gewerk
 * (CalendarItems::ownAssignments). Mit Uhrzeit der Zuordnung in UTC, ohne
 * ganztägig am ersten Tag des Events; Ende vor dem Beginn = am Folgetag.
 * Zeitraum: 90 Tage zurück bis 18 Monate voraus.
 */
final class PersonalIcsFeed
{
    public static function build(User $user): string
    {
        $venue = Setting::lookup(Setting::VENUE_NAME) ?: (string) config('app.name');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//VenueControl//Persoenlich//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . Ics::escape($venue . ' — ' . $user->getFilamentName()),
            'X-WR-TIMEZONE:' . config('app.timezone'),
        ];

        $stamp = gmdate('Ymd\THis\Z');
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'vcontrol.eu';
        // Externe (ohne internen Arbeitsbereich) landen in „Meine Events“
        $extern = !$user->access()->canUseApp();
        $today = CarbonImmutable::today();
        foreach (CalendarItems::ownAssignments($user, $today->subDays(90), $today->addMonths(18)) as $assignment) {
            array_push($lines, ...self::duty($assignment, $stamp, $host, $extern));
        }
        $lines[] = 'END:VCALENDAR';

        return Ics::render($lines);
    }

    /** @return list<string> */
    private static function duty(EventAssignment $assignment, string $stamp, string $host, bool $extern): array
    {
        $event = $assignment->event;
        $day = CarbonImmutable::parse($event->starts_at)->startOfDay();
        $start = self::clock($assignment->starts_at);
        $end = self::clock($assignment->ends_at);
        if ($start !== null && $end !== null) {
            $from = $day->setTimeFromTimeString($start);
            $until = $day->setTimeFromTimeString($end);
            if ($until->lte($from)) {
                $until = $until->addDay();
            }
            $when = ['DTSTART:' . $from->utc()->format('Ymd\THis\Z'), 'DTEND:' . $until->utc()->format('Ymd\THis\Z')];
        } else {
            $when = ['DTSTART;VALUE=DATE:' . $day->format('Ymd'), 'DTEND;VALUE=DATE:' . $day->addDay()->format('Ymd')];
        }

        // Der Feed läuft außerhalb des Panels – das Panel deshalb ausdrücklich
        $url = $extern
            ? ExternEventResource::getUrl('view', ['record' => $event], panel: 'app')
            : EventResource::getUrl('edit', ['record' => $event], panel: 'app') . '?phase=planung&bereich=personal';
        $description = array_filter([
            'Event: ' . $event->title,
            'Beginn: ' . $event->starts_at->format('d.m.Y') . ($event->starts_at->format('H:i') !== '00:00' ? ', ' . $event->starts_at->format('H:i') . ' Uhr' : ''),
            'Status: ' . EventDisplay::statusLabel($event->status),
            $url,
        ]);

        return [
            'BEGIN:VEVENT',
            'UID:role-' . $assignment->id . '@' . $host,
            'DTSTAMP:' . $stamp,
            ...$when,
            'SUMMARY:' . Ics::escape($assignment->role->getLabel() . ' · ' . $event->title),
            'DESCRIPTION:' . Ics::escape(implode("\n", $description)),
            'URL:' . $url,
            'STATUS:' . match (EventDisplay::statusKind($event->status)) {
                'cancelled' => 'CANCELLED',
                'fraglich' => 'TENTATIVE',
                default => 'CONFIRMED',
            },
            'END:VEVENT',
        ];
    }

    private static function clock(mixed $time): ?string
    {
        return filled($time) ? substr((string) $time, 0, 5) : null;
    }
}
