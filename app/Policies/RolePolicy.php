<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Rollen & Rechte ändert nur der System-Admin. Admins der Halle und alle mit
 * dem Recht „Rollen & Rechte“ sehen die Rollen nur an und weisen sie unter
 * Benutzer zu; die System-Admin-Rolle sehen nur System-Admins.
 */
class RolePolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminRollen;
    }

    public function view(User $user, Model $record): bool
    {
        return $record instanceof Role
            && parent::view($user, $record)
            && (!$record->is_system_admin || $user->access()->isSystemAdmin());
    }

    public function create(User $user): bool
    {
        return parent::create($user) && $user->access()->isSystemAdmin();
    }

    public function update(User $user, Model $record): bool
    {
        return $record instanceof Role && $this->create($user);
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
