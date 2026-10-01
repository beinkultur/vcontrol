<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RolePolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminRollen;
    }

    /**
     * Die Admin-Rolle ändern nur Admins, andere Rollen nur, wer deren Rechte
     * selbst hat – wie bei Konten (UserPolicy). Was beim Speichern neu dazukommt,
     * prüfen CreateRole und EditRole.
     */
    public function update(User $user, Model $record): bool
    {
        if (!parent::update($user, $record) || !$record instanceof Role) {
            return false;
        }

        return $record->is_super
            ? $user->access()->isSuper()
            : $user->access()->covers($record->permissions ?? [], $record->calendar_permissions ?? []);
    }

    /** Systemrollen und Rollen, die noch Benutzer haben, bleiben stehen. */
    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Role
            && $this->update($user, $record)
            && !$record->is_system
            && !$record->users()->exists();
    }
}
