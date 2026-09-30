<?php

namespace App\Support;

use App\Enums\AssignmentRole;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\EventService;

/**
 * Fortschritt der drei Phasen wie in der PHP-Version (EventProgress): Anteil der
 * erfüllten Prüfungen in Prozent. Liest nur geladene Beziehungen – in Listen
 * deshalb RELATIONS per with() vorladen.
 */
final class EventProgress
{
    /** Was phases() braucht. */
    public const RELATIONS = ['finance', 'pr', 'schedule', 'checklist', 'stage', 'operation', 'assignments', 'services', 'rooms'];

    /** @return array{buchung: int, planung: int, durchfuehrung: int} Prozent 0–100 */
    public static function phases(Event $event): array
    {
        return [
            'buchung' => self::percent(self::bookingChecks($event)),
            'planung' => self::percent(self::planningChecks($event)),
            'durchfuehrung' => self::percent(self::executionChecks($event)),
        ];
    }

    /**
     * Planungsbereiche mit „begonnen“ wie auf dem Dashboard der PHP-Version.
     *
     * @return array<string, bool> Reiter-ID => begonnen
     */
    public static function planningAreas(Event $event, int $guestCount): array
    {
        $s = $event->schedule;
        $c = $event->checklist;

        return [
            'zeiten' => filled($s?->admission) || filled($s?->start_time) || filled($s?->load_in),
            'checkliste' => collect(['hands', 'cleaning', 'chairs_ordered', 'pvc_setup', 'traffic'])
                ->contains(fn (string $field): bool => self::answered($c?->{$field})),
            'buehne' => self::hasStageSize($event),
            'personal' => $event->assignments->contains(fn (EventAssignment $a): bool => $a->role !== AssignmentRole::ProjectLead),
            'gewerke' => self::filledServices($event) > 0,
            'gaeste' => $guestCount > 0,
            'sonstiges' => filled($event->wlan) || (bool) $c?->briefing_complete,
        ];
    }

    /** Leistungen, bei denen ein Gewerk, Anbieter oder eine Notiz eingetragen ist. */
    public static function filledServices(Event $event): int
    {
        return $event->services
            ->filter(fn (EventService $s): bool => $s->trade_id !== null || filled($s->provider_label) || filled($s->note))
            ->count();
    }

    /** @return list<bool> */
    private static function bookingChecks(Event $e): array
    {
        return [
            filled($e->title),
            $e->promoter_id !== null,
            $e->starts_at !== null,
            filled($e->event_type1),
            filled($e->event_type2),
            $e->pax_expected > 0,
            filled($e->seating),
            $e->assignments->contains('role', AssignmentRole::ProjectLead),
            filled($e->finance?->contract_status),
            filled($e->finance?->price_list) || $e->finance?->rent !== null,
            filled($e->pr?->pr_status) || $e->pr?->pr_date !== null,
            filled($e->areas) || filled($e->ticketing),
        ];
    }

    /** @return list<bool> */
    private static function planningChecks(Event $e): array
    {
        $s = $e->schedule;
        $c = $e->checklist;

        return [
            filled($s?->admission) || filled($s?->start_time),
            filled($s?->end_time),
            filled($s?->load_in),
            self::answered($c?->hands),
            self::answered($c?->cleaning),
            self::answered($c?->chairs_ordered),
            self::hasStageSize($e),
            (float) $e->stage?->height > 0,
            // PHP: Durchführung (Freitext, nicht übernommen) oder Aufschließen – hier: eine Rolle außer PL
            $e->assignments->contains(fn (EventAssignment $a): bool => $a->role !== AssignmentRole::ProjectLead),
            self::filledServices($e) > 0,
            filled($e->wlan) || (bool) $c?->briefing_complete,
        ];
    }

    /** @return list<bool> */
    private static function executionChecks(Event $e): array
    {
        $c = $e->checklist;
        $op = $e->operation;

        return [
            $e->pax !== null,
            $e->rooms->isNotEmpty() || filled($op?->backstages) || filled($op?->offices),
            $op?->bus_power !== null,
            in_array($c?->merch_fee_check, ['yes', 'no'], true)
                || in_array($c?->special_cleaning, ['yes', 'no'], true)
                || $op?->house_delay !== null
                || $c?->power_ant !== null
                || $e->stage?->sold_out_award !== null,
            (bool) $e->closed,
        ];
    }

    private static function hasStageSize(Event $e): bool
    {
        return (float) $e->stage?->width > 0 && (float) $e->stage?->depth > 0;
    }

    private static function answered(mixed $value): bool
    {
        return in_array($value, ['yes', 'no', 'na'], true);
    }

    /** @param  list<bool>  $checks */
    private static function percent(array $checks): int
    {
        return (int) round(count(array_filter($checks)) / count($checks) * 100);
    }
}
