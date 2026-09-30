<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ansprechpartner eines Veranstalters. */
#[Fillable(['first_name', 'last_name', 'role', 'phone', 'email', 'sort_order'])]
class PromoterContact extends Model
{
    /** @return BelongsTo<Promoter, $this> */
    public function promoter(): BelongsTo
    {
        return $this->belongsTo(Promoter::class);
    }
}
