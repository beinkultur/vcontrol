<?php

namespace App\Access;

/**
 * Module, die eine Venue bucht. Der System-Admin gibt sie unter Verwaltung ›
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
    case PriceLists = 'preislisten';
    case Advancing = 'advancing';
    case Operations = 'betrieb';

    public function label(): string
    {
        return match ($this) {
            self::StagePlan => 'Bühnenplan',
            self::SeatingPlan => 'Saalplan',
            self::GuestList => 'Gästeliste',
            self::Accounting => 'Buchhaltung',
            self::PriceLists => 'Preislisten & Costing',
            self::Advancing => 'Advancing',
            self::Operations => 'Betrieb',
        };
    }

    /** Was dazugehört – steht unter Verwaltung › System neben dem Haken. */
    public function description(): string
    {
        return match ($this) {
            self::StagePlan => 'Planung › Bühne je Event mit Maßen, Podest-Rechnung und dem Plan mit Podest-Raster (Drucken, SVG), die Bühnenwerte unter Verwaltung › Venue, die Bühne für Externe und im Daysheet.',
            self::SeatingPlan => 'Standard-Saalpläne der Venue und eigene Pläne je Event (PDF), auch für Externe und im Daysheet. Bereiche und Bestuhlung gibt es immer.',
            self::GuestList => 'Gäste mit Freikarten je Event und die Liste zum Drucken für den Einlass.',
            self::Accounting => 'Finanzen, FIBU-Status und Eingangsrechnungen je Event, die Buchhaltungsliste und die Finanz-Warnung.',
            self::PriceLists => 'Preislisten der Venue mit Kostenpunkten, die Preisliste je Event und das Costing, das der Eventmanager daraus erstellt und als PDF verschickt. Die Buchhaltung sieht beides.',
            self::Advancing => 'Fragenkatalog an den Veranstalter je Event: vorausgefüllt unter Planung › Advancing, Link ohne Konto per Mail, Antworten mit Markierung der Änderungen. Die Kataloge spielt der Betreiber ein (vc:advancing-catalog).',
            self::Operations => 'Durchführung › Betrieb je Event: Räume (Backstage, Büro), Bus-Strom, Zählerstände je Event und der Check (Sonderreinigung, Haus-Delay, Merch-Fee …). Ohne das Modul gilt die Durchführung als erfasst: vergangene Events bleiben nicht unter „Zukünftige“ stehen.',
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
