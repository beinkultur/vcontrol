<?php

namespace App\Policies;

use App\Access\Area;
use App\Access\Level;
use App\Models\Calendar;
use App\Models\CalendarEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Eigene Kalendereinträge: sehen, wer die Ebene lesen darf; anlegen, ändern
 * und löschen, wer sie bearbeiten darf (Kalender-Rechte je Ebene an Rolle und
 * Konto) – wie requireCalendarLayer der PHP-Version.
 */
class CalendarEntryPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::Kalender;
    }

    public function view(User $user, Model $record): bool
    {
        return $record instanceof CalendarEntry && $user->access()->canCalendar($record->calendar_key);
    }

    /** Anlegen geht, sobald es eine Ebene mit eigenen Einträgen gibt, die man bearbeiten darf. */
    public function create(User $user): bool
    {
        return $this->viewAny($user) && self::editableKeys($user) !== [];
    }

    public function update(User $user, Model $record): bool
    {
        return $record instanceof CalendarEntry
            && $this->viewAny($user)
            && in_array($record->calendar_key, self::editableKeys($user), true);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    /** @return list<string> Ebenen mit eigenen Einträgen, die dieses Konto bearbeiten darf */
    public static function editableKeys(User $user): array
    {
        return Calendar::query()->ordered()->pluck('key')
            ->filter(fn (string $key): bool => !in_array($key, Calendar::DERIVED, true) && $user->access()->canCalendar($key, Level::Edit))
            ->values()
            ->all();
    }
}
