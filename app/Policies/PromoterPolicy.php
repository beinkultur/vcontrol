<?php

namespace App\Policies;

use App\Access\Area;

/** Veranstalter werden archiviert, nicht gelöscht – sie hängen an Events und VA-IDs. */
class PromoterPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::Veranstalter;
    }
}
