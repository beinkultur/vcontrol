<?php

namespace App\Support;

use App\Enums\AssignmentRole;
use App\Models\Employee;
use App\Models\Event;
use Carbon\CarbonInterface;

/** Darstellung eines Events in Liste und Workspace wie in der PHP-Version (EventList). */
final class EventDisplay
{
    private const WEEKDAYS = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

    private const MONTHS = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    public static function weekday(CarbonInterface $date): string
    {
        return self::WEEKDAYS[$date->dayOfWeek];
    }

    public static function month(CarbonInterface $date): string
    {
        return self::MONTHS[$date->month] . ' ' . $date->year;
    }

    /** Mehrtägig ab mehr als einem Kalendertag (Ende = Beginn + 1 zählt nicht, Ganztags-Import). */
    public static function isMultiDay(Event $event): bool
    {
        return $event->starts_at !== null && $event->ends_at !== null
            && $event->starts_at->copy()->startOfDay()->diffInDays($event->ends_at->copy()->startOfDay()) > 1;
    }

    /** @return 'fraglich'|'cancelled'|null */
    public static function statusKind(?string $status): ?string
    {
        return match (mb_strtolower(trim((string) $status))) {
            'fraglich' => 'fraglich',
            'abgesagt', 'storniert', 'cancelled' => 'cancelled',
            default => null,
        };
    }

    public static function statusLabel(?string $status): string
    {
        $status = trim((string) $status);

        return match (true) {
            $status === '' => '–',
            mb_strtolower($status) === 'storniert' => 'Abgesagt',
            default => $status,
        };
    }

    public static function promoterShort(Event $event): ?string
    {
        return $event->promoter?->short_name ?: $event->promoter?->name;
    }

    /** Kürzel der Projektleitung wie in der Liste der PHP-Version, sonst der Name. */
    public static function projectLeadShort(Event $event): ?string
    {
        $lead = $event->assignments->firstWhere('role', AssignmentRole::ProjectLead);
        if ($lead === null) {
            return null;
        }
        $assignee = $lead->assignee;

        return $assignee instanceof Employee && filled($assignee->initials) ? $assignee->initials : $lead->assigneeName();
    }

    public static function projectLeadName(Event $event): ?string
    {
        return $event->assignments->firstWhere('role', AssignmentRole::ProjectLead)?->assigneeName();
    }

    /** Nur bei vollständiger Bestuhlung (Stuhlmiete) – wie das Stuhl-Symbol der PHP-Version. */
    public static function fullySeated(Event $event): bool
    {
        $seating = array_map(fn ($value): string => mb_strtolower(trim((string) $value)), (array) $event->seating);

        return $seating === ['bestuhlt'];
    }

    /** „Do, 01.10.2026 · FKP · VA-ID 1050.01“ wie im Kopf des Workspace der PHP-Version. */
    public static function meta(Event $event): string
    {
        return collect([
            $event->starts_at ? self::weekday($event->starts_at) . ', ' . $event->starts_at->format('d.m.Y') : 'Datum offen',
            self::promoterShort($event),
            'VA-ID ' . ($event->va_id ?: '–'),
            self::statusLabel($event->status),
        ])->filter()->implode(' · ');
    }
}
