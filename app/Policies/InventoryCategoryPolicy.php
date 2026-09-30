<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\InventoryCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Inventar-Kategorien. */
class InventoryCategoryPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminInventar;
    }

    /** Nur leere Kategorien – Kategorien mit Artikeln werden deaktiviert. */
    public function delete(User $user, Model $record): bool
    {
        return $record instanceof InventoryCategory
            && $user->access()->canEdit($this->area())
            && !$record->items()->exists();
    }

    public function reorder(User $user): bool
    {
        return $user->access()->canEdit($this->area());
    }
}
