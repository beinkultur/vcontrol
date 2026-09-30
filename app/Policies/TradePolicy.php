<?php

namespace App\Policies;

use App\Access\Area;

/** Gewerke werden archiviert, nicht gelöscht – Events und Benutzer verweisen darauf. */
class TradePolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminGewerke;
    }
}
