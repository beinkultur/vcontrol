<?php

namespace App\Policies;

use App\Access\Area;

/** Artikel werden deaktiviert statt gelöscht – Übergabeprotokolle verweisen darauf. */
class InventoryItemPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminInventar;
    }
}
