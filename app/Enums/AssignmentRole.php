<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Rollen, die bei einem Event an Benutzer, Kontakte (ohne Anmeldung) oder Gewerke vergeben werden. */
enum AssignmentRole: string implements HasLabel
{
    case ProjectLead = 'pl';
    case Unlock = 'house_rep_1';
    case HouseRepEarly = 'house_rep_2';
    case HouseRepLate = 'house_rep_3';
    case Lock = 'house_rep_4';
    case SafetyEarly = 'safety_1';
    case SafetyLate = 'safety_2';

    public function getLabel(): string
    {
        return match ($this) {
            self::ProjectLead => 'Projektleitung',
            self::Unlock => 'Aufschließen',
            self::HouseRepEarly => 'House Rep. früh',
            self::HouseRepLate => 'House Rep. spät',
            self::Lock => 'Abschließen',
            self::SafetyEarly => 'VfV früh',
            self::SafetyLate => 'VfV spät',
        };
    }

    /**
     * Wer die Rolle übernehmen kann, als Typen der Morph-Map: Auf-/Abschließen
     * und House Rep. Personen (Benutzer mit Positionen, Kontakte), VfV dazu
     * Gewerke, die Projektleitung alle (Vorgaben vom 12.10.2026; Kontakte statt
     * Mitarbeiter seit 14.10.2026).
     *
     * @return list<string>
     */
    public function assigneeTypes(): array
    {
        return match ($this) {
            self::Unlock, self::Lock, self::HouseRepEarly, self::HouseRepLate => ['user', 'contact'],
            self::SafetyEarly, self::SafetyLate => ['user', 'contact', 'trade'],
            self::ProjectLead => ['user', 'contact', 'trade'],
        };
    }

    /**
     * Voraussetzung für die Rolle: Zur Wahl stehen dann nur Benutzer und Kontakte
     * mit dieser Position und Gewerke mit diesem Leistungsbereich – VfV seit dem
     * 12.10.2026, House Rep. früh/spät nur mit VL (Veranstaltungsleitung) seit
     * dem 13.10.2026. Auf-/Abschließen ohne. Null: keine.
     */
    public function qualification(): ?string
    {
        return match ($this) {
            self::SafetyEarly, self::SafetyLate => 'VfV',
            self::HouseRepEarly, self::HouseRepLate => 'VL',
            default => null,
        };
    }
}
