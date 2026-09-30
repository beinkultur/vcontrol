<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** In welcher Beziehung ein Benutzer zur Halle steht. */
enum AccountType: string implements HasLabel
{
    case VenueEmployee = 'venue_employee';
    case Freelancer = 'freelancer';
    case TradeAccount = 'trade_account';

    public function getLabel(): string
    {
        return match ($this) {
            self::VenueEmployee => 'Mitarbeiter der Halle',
            self::Freelancer => 'Freelancer',
            self::TradeAccount => 'Gewerk-Account',
        };
    }
}
