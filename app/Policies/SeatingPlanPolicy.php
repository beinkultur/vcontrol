<?php

namespace App\Policies;

use App\Access\Area;
use App\Access\Module;
use App\Models\SeatingPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard-Saalpläne pflegt, wer die Stammdaten der Venue bearbeiten darf
 * (Verwaltung › Venue, in der Regel der Admin). Gelöscht wird nur ein Plan,
 * den kein Event nutzt – sonst deaktivieren. Alles nur mit dem Modul Saalplan.
 */
class SeatingPlanPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminStammdaten;
    }

    public function viewAny(User $user): bool
    {
        return Module::SeatingPlan->isEnabled() && parent::viewAny($user);
    }

    public function create(User $user): bool
    {
        return Module::SeatingPlan->isEnabled() && parent::create($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof SeatingPlan
            && $this->create($user)
            && !$record->events()->exists();
    }
}
