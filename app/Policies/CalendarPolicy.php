<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\Calendar;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Kalender-Ebenen. */
class CalendarPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminKalender;
    }

    /** System-Kalender bleiben. Sobald der Kalender portiert ist: nur ohne Einträge. */
    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Calendar
            && $user->access()->canEdit($this->area())
            && !$record->is_system;
    }

    public function reorder(User $user): bool
    {
        return $user->access()->canEdit($this->area());
    }
}
