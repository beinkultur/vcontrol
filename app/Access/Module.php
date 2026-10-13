<?php

namespace App\Access;

/**
 * Module, die eine Halle bucht. Der System-Admin gibt sie unter Verwaltung ›
 * System frei (App\Access\Modules). Ohne Freigabe ist ein Modul für alle
 * ausgeblendet, auch für Admins und System-Admins – Eingegebenes bleibt
 * erhalten und ist wieder da, sobald das Modul frei ist.
 */
enum Module: string
{
    case StagePlan = 'buehnenplan';
    case SeatingPlan = 'saalplan';
    case GuestList = 'gaesteliste';
    case Accounting = 'buchhaltung';

    public function label(): string
    {
        return match ($this) {
            self::StagePlan => 'Bühnenplan',
            self::SeatingPlan => 'Saalplan',
            self::GuestList => 'Gästeliste',
            self::Accounting => 'Buchhaltung',
        };
    }

    /** Was dazugehört – steht unter Verwaltung › System neben dem Haken. */
    public function description(): string
    {
        return match ($this) {
            self::StagePlan => 'Plan mit Podest-Raster je Event, zum Drucken und als SVG, auch für Externe und im Daysheet. Die Bühnenmaße unter Planung › Bühne gibt es immer.',
            self::SeatingPlan => 'Standard-Saalpläne der Halle und eigene Pläne je Event (PDF), auch für Externe und im Daysheet. Bereiche und Bestuhlung gibt es immer.',
            self::GuestList => 'Gäste mit Freikarten je Event und die Liste zum Drucken für den Einlass.',
            self::Accounting => 'Finanzen, FIBU-Status und Eingangsrechnungen je Event, die Buchhaltungsliste und die Finanz-Warnung.',
        };
    }

    /**
     * Bereiche (Rechte), die das Modul mitbringt: Ohne Freigabe sind sie für alle
     * zu wie abgeschaltete Bereiche, die Rechte der Rollen bleiben gespeichert.
     *
     * @return list<Area>
     */
    public function areas(): array
    {
        return $this === self::Accounting ? [Area::Buchhaltung] : [];
    }

    public function isEnabled(): bool
    {
        return Modules::current()->isEnabled($this);
    }
}
