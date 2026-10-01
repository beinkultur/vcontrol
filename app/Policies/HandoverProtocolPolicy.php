<?php

namespace App\Policies;

use App\Policies\Concerns\OperationsPolicy;

/** Übergabeprotokolle werden wie in der PHP-Version nicht gelöscht, nur zurückerhalten. */
class HandoverProtocolPolicy extends AreaPolicy
{
    use OperationsPolicy;
}
