<?php

namespace App\Policies;

use App\Access\Area;
use App\Access\Module;
use App\Models\Costing;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Costings erstellt, ändert und verschickt, wer Events bearbeitet
 * (Eventmanager); die Buchhaltung sieht sie, andere Leserollen nicht – es sind
 * Finanzdaten. Eine verschickte Fassung ändert niemand mehr, gelöscht werden nur
 * Entwürfe. Alles nur mit dem Modul Preislisten & Costing.
 */
class CostingPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::Events;
    }

    public function viewAny(User $user): bool
    {
        $access = $user->access();

        return Module::PriceLists->isEnabled() && ($access->canEdit(Area::Events) || $access->can(Area::Buchhaltung));
    }

    public function create(User $user): bool
    {
        return Module::PriceLists->isEnabled() && parent::create($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $record instanceof Costing && !$record->isSent() && $this->create($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }
}
