<?php

namespace App\Access;

use App\Models\Calendar;
use App\Models\Role;
use App\Models\User;

/**
 * Was ein Benutzer darf. Mehrere Rollen addieren sich: je Bereich zählt die
 * höchste Stufe. Die Super-Rolle darf alles – außer in abgeschalteten Modulen.
 * Kalender-Rechte kommen aus den Rollen und zusätzlich vom Benutzer selbst.
 */
final class Access
{
    /** @var array<string, Level>|null */
    private ?array $areas = null;

    /** @var array<string, Level>|null */
    private ?array $calendars = null;

    public function __construct(private readonly User $user)
    {
    }

    public function isSuper(): bool
    {
        return $this->user->roles->contains(fn (Role $role): bool => (bool) $role->is_super);
    }

    public function level(Area $area): Level
    {
        if ($area->isDisabled()) {
            return Level::None;
        }
        if ($this->isSuper()) {
            return Level::Edit;
        }

        return $this->areas()[$area->value] ?? Level::None;
    }

    public function can(Area $area, Level $minimum = Level::Read): bool
    {
        return $this->level($area)->atLeast($minimum);
    }

    public function canEdit(Area $area): bool
    {
        return $this->can($area, Level::Edit);
    }

    /** Mindestens ein Bereich der Gruppe ist lesbar. */
    public function canAnyIn(string $group): bool
    {
        foreach (Area::inGroup($group) as $area) {
            if ($this->can($area)) {
                return true;
            }
        }

        return false;
    }

    /** Zugang zum internen Arbeitsbereich – alles außer den Extern-Ansichten. */
    public function canUseApp(): bool
    {
        return $this->canAnyIn(Area::GROUP_MODULE) || $this->canAnyIn(Area::GROUP_ADMIN);
    }

    /**
     * Extern-Bereich (Freelancer, Gewerke): „Meine Events“. Kalender und Schichten
     * extern gibt es in Laravel noch nicht – sie allein öffnen nichts.
     */
    public function canUseExtern(): bool
    {
        return $this->can(Area::EventsExtern);
    }

    public function calendarLevel(string $calendarKey): Level
    {
        if ($this->isSuper()) {
            return Level::Edit;
        }

        return $this->calendars()[$calendarKey] ?? Level::None;
    }

    public function canCalendar(string $calendarKey, Level $minimum = Level::Read): bool
    {
        return $this->calendarLevel($calendarKey)->atLeast($minimum);
    }

    /**
     * „Nie mehr Rechte vergeben, als man selbst hat“: Liegen diese Stufen
     * (Bereich => Stufe, Kalender => Stufe) alle innerhalb der eigenen? Admins
     * dürfen alles. Verglichen werden die vergebenen Stufen auch abgeschalteter
     * Module – sie wirken wieder, sobald das Modul an ist.
     *
     * @param  array<string, mixed>  $areas
     * @param  array<string, mixed>  $calendars
     */
    public function covers(array $areas, array $calendars = []): bool
    {
        if ($this->isSuper()) {
            return true;
        }
        foreach ($areas as $key => $value) {
            // Unbekannte Schlüssel wirken nirgends (level() fragt nur Area ab)
            if (Area::tryFrom((string) $key) !== null
                && Level::fromStored($value)->rank() > ($this->areas()[(string) $key] ?? Level::None)->rank()) {
                return false;
            }
        }
        foreach ($calendars as $key => $value) {
            if (Level::fromStored($value)->rank() > $this->calendarLevel((string) $key)->rank()) {
                return false;
            }
        }

        return true;
    }

    /** Hat dieser Benutzer höchstens die eigenen Rechte? Admins nur gegenüber Admins. */
    public function coversUser(User $other): bool
    {
        $theirs = $other->access();
        if ($theirs->isSuper()) {
            return $this->isSuper();
        }

        return $this->covers(
            array_map(fn (Level $level): string => $level->value, $theirs->areas()),
            array_map(fn (Level $level): string => $level->value, $theirs->calendars()),
        );
    }

    /** @return array<string, Level> */
    private function areas(): array
    {
        return $this->areas ??= self::merge(
            $this->user->roles->map(fn (Role $role): array => $role->permissions ?? [])->all()
        );
    }

    /** @return array<string, Level> */
    private function calendars(): array
    {
        if ($this->calendars !== null) {
            return $this->calendars;
        }

        $sources = $this->user->roles->map(fn (Role $role): array => $role->calendar_permissions ?? [])->all();
        $sources[] = $this->user->calendar_permissions ?? [];
        $merged = self::merge($sources);

        // Den persönlichen Kalender sieht jeder, der überhaupt einen Kalender sehen darf
        if ($this->can(Area::Kalender) || $this->can(Area::KalenderExtern)) {
            $merged[Calendar::PERSONAL] = Level::max($merged[Calendar::PERSONAL] ?? Level::None, Level::Read);
        }

        return $this->calendars = $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, Level>
     */
    private static function merge(array $sources): array
    {
        $merged = [];
        foreach ($sources as $levels) {
            foreach ($levels as $key => $value) {
                $merged[$key] = Level::max($merged[$key] ?? Level::None, Level::fromStored($value));
            }
        }

        return $merged;
    }
}
