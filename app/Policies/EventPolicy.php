<?php

namespace App\Policies;

use App\Access\Area;

/** Events werden storniert, nicht gelöscht. */
class EventPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::Events;
    }
}
