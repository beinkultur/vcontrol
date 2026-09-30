<?php

namespace App\Policies;

use App\Access\Area;

/** Mitarbeiter werden nicht gelöscht – Benutzer und Events verweisen darauf. */
class EmployeePolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminMitarbeiter;
    }
}
