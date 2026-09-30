<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Räume lassen sich löschen und sortieren. Sobald Events portiert sind: nur löschen, wenn kein Event den Raum belegt. */
class RoomPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminRaeume;
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->access()->canEdit($this->area());
    }

    public function reorder(User $user): bool
    {
        return $user->access()->canEdit($this->area());
    }
}
