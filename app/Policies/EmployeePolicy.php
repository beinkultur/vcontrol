<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Mitarbeiter werden archiviert; löschen nur, solange kein Event und kein
 * Benutzerkonto auf sie verweist.
 */
class EmployeePolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminMitarbeiter;
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Employee
            && $user->access()->canEdit($this->area())
            && !$record->isInUse();
    }
}
