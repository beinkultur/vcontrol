<?php

namespace App\Access;

/**
 * Bereiche, für die eine Rolle Rechte bekommt. Die Werte sind die Schlüssel der
 * PHP-Version – der Import übernimmt die Rechte-Matrix unverändert.
 */
enum Area: string
{
    public const GROUP_MODULE = 'Module';
    public const GROUP_ADMIN = 'Administration';
    public const GROUP_EXTERN = 'Extern';

    case Events = 'events';
    case Anfragen = 'anfragen';
    case EventsOperations = 'events_operations';
    case Kalender = 'kalender';
    case Schichten = 'schichten';
    case Zaehler = 'zaehler';
    case Buchhaltung = 'buchhaltung';
    case Protokolle = 'protokolle';
    case Codes = 'codes';
    case Veranstalter = 'veranstalter';
    case Audit = 'audit';
    case AdminBenutzer = 'admin_benutzer';
    case AdminRollen = 'admin_rollen';
    case AdminStammdaten = 'admin_stammdaten';
    case AdminInventar = 'admin_inventar';
    case AdminGewerke = 'admin_gewerke';
    case AdminMitarbeiter = 'admin_mitarbeiter';
    case AdminFeldoptionen = 'admin_feldoptionen';
    case AdminRaeume = 'admin_raeume';
    case AdminKalender = 'admin_kalender';
    case AdminSchichtplanung = 'admin_schichtplanung';
    case EventsExtern = 'events_extern';
    case SchichtenExtern = 'schichten_extern';
    case KalenderExtern = 'kalender_extern';

    public function label(): string
    {
        return match ($this) {
            self::Events => 'Events',
            self::Anfragen => 'Anfragen',
            self::EventsOperations => 'Event-Operationen (Übergabe, Bestellscheine, Schäden)',
            self::Kalender => 'Kalender',
            self::Schichten => 'Schichten',
            self::Zaehler => 'Zählerstände',
            self::Buchhaltung => 'Buchhaltung',
            self::Protokolle => 'Protokolle etc.',
            self::Codes => 'Codes Tageszugang',
            self::Veranstalter => 'Veranstalter',
            self::Audit => 'Audit',
            self::AdminBenutzer => 'Benutzerverwaltung',
            self::AdminRollen => 'Rollen & Rechte',
            self::AdminStammdaten => 'Stammdaten',
            self::AdminInventar => 'Inventar',
            self::AdminGewerke => 'Gewerke',
            self::AdminMitarbeiter => 'Mitarbeiter',
            self::AdminFeldoptionen => 'Event-Feldoptionen',
            self::AdminRaeume => 'Räume',
            self::AdminKalender => 'Kalender (Verwaltung)',
            self::AdminSchichtplanung => 'Schichtplanung',
            self::EventsExtern => 'Events (extern)',
            self::SchichtenExtern => 'Schichten (extern)',
            self::KalenderExtern => 'Kalender (extern)',
        };
    }

    public function group(): string
    {
        return match (true) {
            str_starts_with($this->value, 'admin_') => self::GROUP_ADMIN,
            str_ends_with($this->value, '_extern') => self::GROUP_EXTERN,
            default => self::GROUP_MODULE,
        };
    }

    /**
     * Abgeschaltete Module (config venuecontrol.disabled_areas) sind für alle
     * gesperrt, auch für Admins. Gespeicherte Rechte bleiben erhalten.
     */
    public function isDisabled(): bool
    {
        return in_array($this->value, (array) config('venuecontrol.disabled_areas', []), true);
    }

    /** @return list<self> */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(self::cases(), fn (self $area): bool => $area->group() === $group));
    }

    /** @return list<string> */
    public static function groups(): array
    {
        return [self::GROUP_MODULE, self::GROUP_ADMIN, self::GROUP_EXTERN];
    }
}
