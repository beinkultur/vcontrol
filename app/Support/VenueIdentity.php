<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * Name der Venue an einer Stelle (bisher an 13 Stellen je eigens gelesen). Mit
 * mehreren Venues je Installation (docs/superpowers/specs/2026-11-17-mehrere-venues-design.md)
 * kommt hier die Venue des Events bzw. der Betreiber dazu.
 */
final class VenueIdentity
{
    /** Eingetragener Name (Verwaltung › Venue), ohne Rückfall; null, wenn keiner da ist – auch ohne Datenbank (Wartungsseite). */
    public static function configured(): ?string
    {
        try {
            $name = trim((string) Setting::lookup(Setting::VENUE_NAME));
        } catch (Throwable) {
            return null;
        }

        return $name !== '' ? $name : null;
    }

    /** Name für Ausgaben: der eingetragene, sonst der Name der App. */
    public static function name(): string
    {
        return self::configured() ?? (string) config('app.name');
    }
}
