<?php

namespace App\Policies;

use App\Policies\Concerns\OperationsPolicy;

/** Schäden werden wie in der PHP-Version nicht gelöscht, nur als behoben markiert. */
class DamagePolicy extends AreaPolicy
{
    use OperationsPolicy;
}
