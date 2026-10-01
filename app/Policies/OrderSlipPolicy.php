<?php

namespace App\Policies;

use App\Policies\Concerns\OperationsPolicy;

/** Bestellscheine werden wie in der PHP-Version nicht gelöscht. */
class OrderSlipPolicy extends AreaPolicy
{
    use OperationsPolicy;
}
