<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Strenger als die PHP-Version: Konten mit mehr Rechten als den eigenen ändert
 * nur, wer diese Rechte selbst hat – Admin-Konten also nur Admins. Sonst könnte
 * jemand mit Benutzerverwaltung dort ein Passwort setzen und sich mit diesen
 * Rechten anmelden („nie mehr Rechte vergeben, als man selbst hat“).
 */
class UserPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminBenutzer;
    }

    public function update(User $user, Model $record): bool
    {
        return $record instanceof User
            && $user->access()->canEdit($this->area())
            && $user->access()->coversUser($record);
    }

    /** Nicht sich selbst und nicht den letzten aktiven Admin. */
    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record)
            && $record->isNot($user)
            && !$record->isLastSuper();
    }
}
