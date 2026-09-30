<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Auswahlwerte lassen sich löschen und sortieren; Events speichern den Wert als Text, nicht als Verweis. */
class FieldOptionPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminFeldoptionen;
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
