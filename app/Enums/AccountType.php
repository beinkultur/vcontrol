<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * In welcher Beziehung ein Benutzer zur Venue steht. Personen-Konten
 * (Mitarbeiter der Venue, Freelancer) tragen Positionen (VL, VfV …) und werden
 * selbst im Personal der Events eingetragen; Gewerk-Accounts sind mit einem
 * Gewerk verknüpft – dessen Dienste und Events gelten dann als die eigenen
 * (persönlicher Kalender, „Meine Events“).
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

    /** Person mit Positionen, im Personal der Events wählbar (seit 14.10.2026 statt der Mitarbeiter der Stammdaten) */
    public function hasPositions(): bool
    {
        return $this === self::VenueEmployee || $this === self::Freelancer;
    }

    /** Verknüpfung mit einem Gewerk (users.trade_id); mehrere Konten je Gewerk */
    public function linksTrade(): bool
    {
        return $this === self::TradeAccount;
    }
}
