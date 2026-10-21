<?php

namespace App\Support;

use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ist die Durchführung eines Events vollständig erfasst? (Vorgabe vom
 * 21.10.2026) Jeder Punkt unter Durchführung › Betrieb › Check hat einen Wert –
 * egal welchen –, Bus-Strom auch (0 zählt), und bei VA-Kategorie 1 „Konzert“ ist
 * mindestens ein Raum Backstage oder Büro. Vergangene Events, bei denen etwas
 * fehlt, bleiben in der Event-Liste stehen (Zeitraum „Zukünftige“, Event::
 * scopeExecutionIncomplete), der Fortschritt der Durchführung zählt es mit.
 */
final class ExecutionCheck
{
    /**
     * Die Punkte des Abschnitts „Check“: Spalte => [Beziehung, Beschriftung]. PAX
     * abgerechnet steht seit 21.10.2026 unter Buchung › Buchhaltung.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CHECKS = [
        'special_cleaning' => ['checklist', 'Sonderreinigung'],
        'house_delay' => ['operation', 'Haus-Delay'],
        'power_ant' => ['checklist', 'Miete Elektro-Ameise'],
        'house_rig_early' => ['checklist', 'Haus-Rig ab 7 Uhr'],
        'person_lift' => ['operation', 'Personenlift'],
        'sold_out_award' => ['stage', 'Sold-Out-Award'],
        'merch_fee' => ['checklist', 'Merch-Fee'],
        'merch_fee_check' => ['checklist', 'Merch-Fee eingesammelt'],
    ];

    /** Textspalten: auch leerer Text zählt als fehlend (die übrigen sind ja/nein bzw. eine Zahl, 0 ist ein Wert) */
    private const TEXT_COLUMNS = ['special_cleaning', 'merch_fee', 'merch_fee_check'];

    /** Auswahl als Text: nur diese Werte zählen (ein altes „entfällt“ der Sonderreinigung zeigt das Formular als leer) */
    private const ALLOWED = ['special_cleaning' => ['yes', 'no'], 'merch_fee_check' => ['yes', 'no', 'na', 'transfer']];

    public const CONCERT = 'konzert';

    /**
     * Was noch fehlt, in der Reihenfolge des Formulars.
     *
     * @return list<string>
     */
    public static function missing(Event $event): array
    {
        $missing = self::missingChecks($event);
        if ($event->operation?->bus_power === null) {
            $missing[] = 'Bus-Strom';
        }
        $hasRoom = $event->relationLoaded('rooms') ? $event->rooms->isNotEmpty() : $event->rooms()->exists();
        if (self::isConcert($event) && !$hasRoom) {
            $missing[] = 'Raum (Backstage oder Büro)';
        }

        return $missing;
    }

    /**
     * Nur die Punkte des Abschnitts „Check“ ohne Wert.
     *
     * @return list<string>
     */
    public static function missingChecks(Event $event): array
    {
        $missing = [];
        foreach (self::CHECKS as $column => [$relation, $label]) {
            $value = $event->{$relation}?->{$column};
            if (self::blank($value) || (isset(self::ALLOWED[$column]) && !in_array($value, self::ALLOWED[$column], true))) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    public static function isComplete(Event $event): bool
    {
        return self::missing($event) === [];
    }

    public static function isConcert(Event $event): bool
    {
        return mb_strtolower(trim((string) $event->event_type1)) === self::CONCERT;
    }

    /**
     * SQL-Fassung von „es fehlt etwas“ für Event::scopeExecutionIncomplete.
     *
     * @param  Builder<Event>  $query
     */
    public static function applyIncomplete(Builder $query): void
    {
        $byRelation = [];
        foreach (self::CHECKS as $column => [$relation]) {
            $byRelation[$relation][] = $column;
        }
        $byRelation['operation'][] = 'bus_power';

        $query->where(function (Builder $q) use ($byRelation): void {
            foreach ($byRelation as $relation => $columns) {
                // Fehlt die Zeile oder ist eine der Spalten leer
                $q->orWhereDoesntHave($relation, function (Builder $r) use ($columns): void {
                    foreach ($columns as $column) {
                        $r->whereNotNull($column);
                        if (isset(self::ALLOWED[$column])) {
                            $r->whereIn($column, self::ALLOWED[$column]);
                        } elseif (in_array($column, self::TEXT_COLUMNS, true)) {
                            $r->whereRaw('TRIM(' . $column . ") <> ''");
                        }
                    }
                });
            }
            $q->orWhere(fn (Builder $concert) => $concert
                ->whereRaw('LOWER(TRIM(event_type1)) = ?', [self::CONCERT])
                ->whereDoesntHave('rooms'));
        });
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
