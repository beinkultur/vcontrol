<?php

namespace App\Filament\Support;

use App\Access\Level;
use App\Models\Calendar;
use Filament\Forms\Components\ToggleButtons;

/** Formularfelder für Rechte: je Bereich oder Kalender eine Stufenwahl. */
final class AccessFields
{
    public static function level(string $statePath, string $label): ToggleButtons
    {
        return ToggleButtons::make($statePath)
            ->label($label)
            ->options(Level::options())
            ->colors([
                Level::None->value => 'gray',
                Level::Read->value => 'info',
                Level::Edit->value => 'success',
            ])
            ->grouped()
            ->default(Level::None->value)
            ->formatStateUsing(fn (?string $state): string => $state ?? Level::None->value);
    }

    /**
     * Eine Stufenwahl je Kalender-Ebene.
     *
     * @return list<ToggleButtons>
     */
    public static function calendars(string $statePath): array
    {
        return Calendar::query()->ordered()->get()
            ->map(fn (Calendar $calendar): ToggleButtons => self::level($statePath . '.' . $calendar->key, $calendar->name))
            ->values()
            ->all();
    }
}
