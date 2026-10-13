<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * In welcher Beziehung ein Benutzer zur Venue steht. Personen-Konten
 * (Mitarbeiter der Venue, Freelancer) lassen sich mit einem Mitarbeiter der
 * Stammdaten verknüpfen, Gewerk-Accounts mit einem Gewerk – dessen Dienste und
 * Events gelten dann als die eigenen (persönlicher Kalender, „Meine Events“).
 */
enum AccountType: string implements HasLabel
{
    case VenueEmployee = 'venue_employee';
    case Freelancer = 'freelancer';
    case TradeAccount = 'trade_account';

    public function getLabel(): string
    {
        return match ($this) {
            self::VenueEmployee => 'Mitarbeiter der Venue',
            self::Freelancer => 'Freelancer',
            self::TradeAccount => 'Gewerk-Account',
        };
    }

    /** Verknüpfung mit einem Mitarbeiter der Stammdaten (users.employee_id) */
    public function linksEmployee(): bool
    {
        return $this === self::VenueEmployee || $this === self::Freelancer;
    }

    /** Verknüpfung mit einem Gewerk (users.trade_id); mehrere Konten je Gewerk */
    public function linksTrade(): bool
    {
        return $this === self::TradeAccount;
    }
}
