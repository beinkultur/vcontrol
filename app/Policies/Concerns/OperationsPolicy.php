<?php

namespace App\Policies\Concerns;

use App\Access\Area;
use App\Models\User;

/**
 * Rechte für Übergabeprotokolle und Bestellscheine wie in der PHP-Version:
 * sehen, wer das Event oder die Protokolle lesen darf; anlegen, zurückerhalten
 * und unterschreiben mit Schreibrecht auf „Event-Operationen“.
 */
trait OperationsPolicy
{
    protected function area(): Area
    {
        return Area::EventsOperations;
    }

    public function viewAny(User $user): bool
    {
        $access = $user->access();

        return $access->can(Area::Events) || $access->can(Area::Protokolle) || $access->can(Area::EventsOperations);
    }
}
