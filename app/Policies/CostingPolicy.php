<?php

namespace App\Policies;

use App\Access\Area;
use App\Access\Module;
use App\Models\Costing;
use App\Models\User;
use App\Support\CostingSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * Wer Costings sieht, stellt der Admin unter Verwaltung › Halle ein
 * (CostingSettings): die Liste aller Costings (Menüpunkt) und das Costing am
 * Event je nach Rolle. Erstellen, ändern und verschicken darf davon, wer Events
 * bearbeitet. Eine verschickte Fassung ändert niemand mehr, gelöscht werden nur
 * Entwürfe. Alles nur mit dem Modul Preislisten & Costing.
 */
class CostingPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::Events;
    }

    /** Liste aller Costings (Menüpunkt) */
    public function viewAny(User $user): bool
    {
        return Module::PriceLists->isEnabled() && CostingSettings::seesMenu($user);
    }

    public function view(User $user, Model $record): bool
    {
        return Module::PriceLists->isEnabled() && CostingSettings::sees($user);
    }

    public function create(User $user): bool
    {
        return Module::PriceLists->isEnabled() && CostingSettings::sees($user) && parent::create($user);
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
