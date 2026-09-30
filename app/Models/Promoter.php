<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Veranstalter – Kunde der Halle. */
#[Fillable(['short_name', 'name', 'customer_no', 'email', 'address1', 'address2', 'zip', 'city', 'logo_path', 'is_archived'])]
class Promoter extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
        ];
    }

    /** @return HasMany<PromoterContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(PromoterContact::class)->orderBy('sort_order');
    }
}
