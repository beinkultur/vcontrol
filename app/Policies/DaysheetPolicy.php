<?php

namespace App\Policies;

use App\Access\Area;

/**
 * Daysheets sieht, wer Events lesen darf; verschicken und Links sperren, wer
 * Events bearbeiten darf (Eventmanager). Gelöscht wird nichts – ein Daysheet
 * bleibt als Nachweis, wer was wann bekommen hat.
 */
class DaysheetPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::Events;
    }
}
