<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Räume lassen sich sortieren und löschen – aber nur, solange kein Event sie
 * belegt. Belegte Räume werden deaktiviert. Die PHP-Version löschte auch
 * belegte Räume samt ihrer Belegungen.
 */
class RoomPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminRaeume;
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Room
            && $user->access()->canEdit($this->area())
            && !$record->events()->exists();
    }

    public function reorder(User $user): bool
    {
        return $user->access()->canEdit($this->area());
    }
}
