<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\SeatingPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard-Saalpläne pflegt, wer die Stammdaten der Halle bearbeiten darf
 * (Verwaltung › Halle, in der Regel der Admin). Gelöscht wird nur ein Plan,
 * den kein Event nutzt – sonst deaktivieren.
 */
class SeatingPlanPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminStammdaten;
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof SeatingPlan
            && $user->access()->canEdit($this->area())
            && !$record->events()->exists();
    }
}
