<?php

namespace App\Policies;

use App\Access\Area;

/** Kategorien der Bestell-Artikel wie die Artikel selbst. */
class ArticleCategoryPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminInventar;
    }
}
