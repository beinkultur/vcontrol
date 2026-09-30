<?php

namespace App\Access;

/** Zugriffsstufe auf einen Bereich oder eine Kalender-Ebene. */
enum Level: string
{
    case None = 'none';
    case Read = 'read';
    case Edit = 'edit';

    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Read => 1,
            self::Edit => 2,
        };
    }

    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    public static function max(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }

    public static function fromStored(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? self::None;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'Kein Zugriff',
            self::Read => 'Nur lesen',
            self::Edit => 'Bearbeiten',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $level) {
            $options[$level->value] = $level->label();
        }

        return $options;
    }
}
