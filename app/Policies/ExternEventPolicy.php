<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\Event;
use App\Models\User;
use App\Support\Involvement;
use Illuminate\Database\Eloquent\Model;

/**
 * Extern-Bereich „Meine Events“ (wie /extern/events der PHP-Version): die Liste
 * mit Recht „Events (extern)“, ein Event nur, wenn man daran beteiligt ist.
 * Wer intern Events lesen darf, kann jedes Event so ansehen, wie Externe es
 * sehen. Ändern kann hier niemand etwas.
 */
class ExternEventPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::EventsExtern;
    }

    public function view(User $user, Model $record): bool
    {
        if (!$record instanceof Event) {
            return false;
        }
        if ($user->access()->can(Area::Events)) {
            return true;
        }

        return $this->viewAny($user) && Involvement::involves($user, $record);
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
