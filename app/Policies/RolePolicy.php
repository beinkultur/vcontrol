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

    /** Systemrollen und Rollen, die noch Benutzer haben, bleiben stehen. */
    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Role
            && $user->access()->canEdit($this->area())
            && !$record->is_system
            && !$record->users()->exists();
    }
}
