<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Das Änderungsprotokoll lesen alle mit Recht „Audit“ – ändern kann es niemand. */
class AuditLogPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::Audit;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }
}
