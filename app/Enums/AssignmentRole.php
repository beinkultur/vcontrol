<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Rollen, die bei einem Event an Mitarbeiter, Gewerke oder Benutzer vergeben werden. */
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
}
