<?php

namespace App\Access;

use App\Models\Setting;

/**
 * Was diese Venue hat: freigegebene Module (Module, Setting::MODULES) und
 * abgeschaltete Bereiche (Area, Setting::DISABLED_AREAS). Beides schaltet der
 * System-Admin unter Verwaltung › System. Solange dort nichts gespeichert ist,
 * sind alle Module frei und für die Bereiche gilt die Server-Konfiguration
 * (VC_DISABLED_AREAS). Einmal je Anfrage gelesen (scoped, AppServiceProvider) –
 * Area::isDisabled() fällt bei jeder Rechteprüfung an.
 */
final class Modules
{
    /** @var list<string>|null  null: in der Venue nichts gespeichert */
    private ?array $storedAreas;

    /** @var list<string>|null  null: nichts gespeichert, alle Module frei */
    private ?array $storedModules;

    public function __construct()
    {
        $values = Setting::query()->whereIn('key', [Setting::DISABLED_AREAS, Setting::MODULES])->pluck('value', 'key');
        $areas = $values[Setting::DISABLED_AREAS] ?? null;
        $modules = $values[Setting::MODULES] ?? null;
        $this->storedAreas = $areas === null ? null : self::parse($areas, Area::class);
        $this->storedModules = $modules === null ? null : self::parse($modules, Module::class);
    }

    public static function current(): self
    {
        return app(self::class);
    }

    /** @return list<string> Bereichs-Schlüssel: abgeschaltet oder Teil eines nicht freigegebenen Moduls */
    public function disabledAreas(): array
    {
        $areas = $this->switchedOff();
        foreach (Module::cases() as $module) {
            if (!$this->isEnabled($module)) {
                array_push($areas, ...array_map(fn (Area $area): string => $area->value, $module->areas()));
            }
        }

        return array_values(array_unique($areas));
    }

    public function isDisabled(Area $area): bool
    {
        return in_array($area->value, $this->disabledAreas(), true);
    }

    /** @return list<string> Modul-Schlüssel */
    public function enabledModules(): array
    {
        return $this->storedModules ?? array_column(Module::cases(), 'value');
    }

    /**
     * Freigegeben? Vor den Modulen ließ sich die Buchhaltung nur als Bereich
     * abschalten (VC_DISABLED_AREAS) – das gilt weiter, bis unter System
     * gespeichert ist.
     */
    public function isEnabled(Module $module): bool
    {
        return in_array($module->value, $this->enabledModules(), true)
            && array_intersect(array_map(fn (Area $area): string => $area->value, $module->areas()), $this->switchedOff()) === [];
    }

    /** Hat der System-Admin die Bereiche schon gespeichert? Sonst gilt die Server-Konfiguration. */
    public function isStored(): bool
    {
        return $this->storedAreas !== null;
    }

    /**
     * @param  list<Module>  $modules  ab jetzt frei, alle anderen nicht
     * @param  list<Area>  $areas  ab jetzt abgeschaltet, alle anderen an
     */
    public function store(array $modules, array $areas): void
    {
        $this->storedModules = array_values(array_unique(array_map(fn (Module $module): string => $module->value, $modules)));
        $this->storedAreas = array_values(array_unique(array_map(fn (Area $area): string => $area->value, $areas)));
        Setting::put(Setting::MODULES, implode(',', $this->storedModules));
        Setting::put(Setting::DISABLED_AREAS, implode(',', $this->storedAreas));
    }

    /** @return list<string> unter System abgeschaltete Bereiche, ohne die der Module */
    private function switchedOff(): array
    {
        return $this->storedAreas ?? self::parse(implode(',', (array) config('venuecontrol.disabled_areas', [])), Area::class);
    }

    /**
     * @param  class-string<Area>|class-string<Module>  $enum
     * @return list<string>
     */
    private static function parse(string $value, string $enum): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $key): bool => $enum::tryFrom($key) !== null));
    }
}
