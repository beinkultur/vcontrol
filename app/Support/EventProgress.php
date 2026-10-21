<?php

namespace App\Support;

use App\Access\Module;
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
     * Bühne und Gästeliste nur mit ihrem Modul.
     *
     * @return array<string, bool> Reiter-ID => begonnen
     */
    public static function planningAreas(Event $event, int $guestCount, int $fileCount): array
    {
        $s = $event->schedule;
        $c = $event->checklist;

        return array_filter([
            'zeiten' => filled($s?->admission) || filled($s?->start_time) || filled($s?->load_in),
            'checkliste' => collect(['hands', 'cleaning', 'chairs_ordered', 'pvc_setup', 'traffic'])
                ->contains(fn (string $field): bool => self::answered($c?->{$field})),
            'buehne' => Module::StagePlan->isEnabled() ? self::hasStageSize($event) : null,
            'kontakte' => $event->assignments->contains(fn (EventAssignment $a): bool => $a->role !== AssignmentRole::ProjectLead)
                || $event->hasOnsiteContact(),
            'gewerke' => self::filledServices($event) > 0,
            'gaeste' => Module::GuestList->isEnabled() ? $guestCount > 0 : null,
            'dateien' => $fileCount > 0,
            'sonstiges' => filled($event->wlan),
        ], fn (?bool $started): bool => $started !== null);
    }

    /** Leistungen, bei denen ein Gewerk, Anbieter oder eine Notiz eingetragen ist. */
    public static function filledServices(Event $event): int
    {
        return $event->services
            ->filter(fn (EventService $s): bool => !$s->service->isRetired()
                && ($s->trade_id !== null || filled($s->provider_label) || filled($s->note)))
            ->count();
    }

    /**
     * Vertrag und Miete zählen nur mit dem Modul Buchhaltung, die Preisliste nur
     * mit dem Modul Preislisten & Costing – ohne lassen sie sich nicht eintragen.
     * Preisliste oder Miete ist eine Prüfung, wie in der PHP-Version.
     *
     * @return list<bool>
     */
    private static function bookingChecks(Event $e): array
    {
        $accounting = Module::Accounting->isEnabled();
        $priceLists = Module::PriceLists->isEnabled();

        return self::counted([
            filled($e->title),
            $e->promoter_id !== null,
            $e->starts_at !== null,
            filled($e->event_type1),
            filled($e->event_type2),
            $e->pax_expected > 0,
            filled($e->seating),
            $e->assignments->contains('role', AssignmentRole::ProjectLead),
            $accounting ? filled($e->finance?->contract_status) : null,
            ($accounting || $priceLists) ? (($priceLists && $e->price_list_id !== null) || ($accounting && $e->finance?->rent !== null)) : null,
            filled($e->pr?->pr_status) || $e->pr?->pr_date !== null,
            filled($e->areas) || filled($e->ticketing),
        ]);
    }

    /**
     * Bühnenmaße und Höhe zählen nur mit dem Modul Bühnenplan – ohne gibt es
     * den Reiter Bühne nicht.
     *
     * @return list<bool>
     */
    private static function planningChecks(Event $e): array
    {
        $s = $e->schedule;
        $c = $e->checklist;
        $stage = Module::StagePlan->isEnabled();

        return self::counted([
            filled($s?->admission) || filled($s?->start_time),
            filled($s?->end_time),
            filled($s?->load_in),
            self::answered($c?->hands),
            self::answered($c?->cleaning),
            self::answered($c?->chairs_ordered),
            $stage ? self::hasStageSize($e) : null,
            $stage ? (float) $e->stage?->height > 0 : null,
            // PHP: Durchführung (Freitext, nicht übernommen) oder Aufschließen – hier: eine Rolle außer PL
            $e->assignments->contains(fn (EventAssignment $a): bool => $a->role !== AssignmentRole::ProjectLead),
            self::filledServices($e) > 0,
            // „Briefing vollständig“ gibt es seit 12.10.2026 nicht mehr
            filled($e->wlan),
        ]);
    }

    /** @return list<bool> */
    private static function executionChecks(Event $e): array
    {
        $op = $e->operation;

        // PAX abgerechnet zählt seit 21.10.2026 nicht mehr – es steht unter Buchung › Buchhaltung;
        // der Check zählt erst, wenn jeder Punkt einen Wert hat (ExecutionCheck, wie die Event-Liste)
        return [
            $e->rooms->isNotEmpty() || filled($op?->backstages) || filled($op?->offices),
            $op?->bus_power !== null,
            ExecutionCheck::missingChecks($e) === [],
            (bool) $e->closed,
        ];
    }

    /**
     * Nur die Prüfungen, die zählen – die nicht freigegebener Module stehen als null darin.
     *
     * @param  list<bool|null>  $checks
     * @return list<bool>
     */
    private static function counted(array $checks): array
    {
        return array_values(array_filter($checks, fn (?bool $check): bool => $check !== null));
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
