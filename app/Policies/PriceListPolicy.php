<?php

namespace App\Policies;

use App\Access\Area;
use App\Access\Module;
use App\Models\PriceList;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Preislisten pflegt, wer die Stammdaten der Venue bearbeitet (in der Regel der
 * Admin). Lesen darf außerdem die Buchhaltung und, wer Events bearbeitet – der
 * Eventmanager wählt die Preisliste am Event und rechnet danach. Gelöscht wird
 * nur eine Preisliste, die kein Event und kein Costing nutzt – sonst
 * deaktivieren. Alles nur mit dem Modul Preislisten & Costing.
 */
class PriceListPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminStammdaten;
    }

    public function viewAny(User $user): bool
    {
        $access = $user->access();

        return Module::PriceLists->isEnabled()
            && ($access->can(Area::AdminStammdaten) || $access->can(Area::Buchhaltung) || $access->canEdit(Area::Events));
    }

    public function create(User $user): bool
    {
        return Module::PriceLists->isEnabled() && parent::create($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof PriceList
            && $this->create($user)
            && !$record->events()->exists()
            && !$record->costings()->exists();
    }
}
