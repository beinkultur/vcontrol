<?php

namespace App\Access;

use App\Models\Setting;

/**
 * Abgeschaltete Bereiche dieser Halle. Der System-Admin schaltet sie unter
 * Verwaltung › System (Setting::DISABLED_AREAS); solange dort nichts gespeichert
 * ist, gilt die Server-Konfiguration (VC_DISABLED_AREAS). Einmal je Anfrage
 * gelesen (scoped, AppServiceProvider) – Area::isDisabled() fällt bei jeder
 * Rechteprüfung an.
 */
final class Modules
{
    /** @var list<string>|null  null: in der Halle nichts gespeichert */
    private ?array $stored;

    public function __construct()
    {
        $value = Setting::lookup(Setting::DISABLED_AREAS);
        $this->stored = $value === null ? null : self::parse($value);
    }

    public static function current(): self
    {
        return app(self::class);
    }

    /** @return list<string> Bereichs-Schlüssel */
    public function disabled(): array
    {
        return $this->stored ?? self::parse(implode(',', (array) config('venuecontrol.disabled_areas', [])));
    }

    public function isDisabled(Area $area): bool
    {
        return in_array($area->value, $this->disabled(), true);
    }

    /** Hat der System-Admin schon gespeichert? Sonst gilt die Server-Konfiguration. */
    public function isStored(): bool
    {
        return $this->stored !== null;
    }

    /** @param  list<Area>  $areas  ab jetzt abgeschaltet, alle anderen an */
    public function disable(array $areas): void
    {
        $this->stored = array_values(array_unique(array_map(fn (Area $area): string => $area->value, $areas)));
        Setting::put(Setting::DISABLED_AREAS, implode(',', $this->stored));
    }

    /** @return list<string> */
    private static function parse(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $key): bool => Area::tryFrom($key) !== null));
    }
}
