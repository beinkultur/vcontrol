<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Strenger als die PHP-Version: Admin-Konten ändern nur Admins. Sonst könnte
 * jemand mit Benutzerverwaltung das Passwort eines Admins setzen und sich als
 * Admin anmelden.
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
            && ($user->access()->isSuper() || !$record->access()->isSuper());
    }

    /** Nicht sich selbst und nicht den letzten aktiven Admin. */
    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record)
            && $record->isNot($user)
            && !$record->isLastSuper();
    }
}
