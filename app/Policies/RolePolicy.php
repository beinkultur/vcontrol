<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Rollen & Rechte sieht und ändert nur der System-Admin (Wunsch des Betreibers,
 * 12.10.2026) – auch Admins der Venue und Rollen mit dem Recht „Rollen &
 * Rechte“ nicht. Zugewiesen werden Rollen weiterhin unter Benutzer.
 */
class RolePolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminRollen;
    }

    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && $user->access()->isSystemAdmin();
    }

    public function view(User $user, Model $record): bool
    {
        return $record instanceof Role && parent::view($user, $record);
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
