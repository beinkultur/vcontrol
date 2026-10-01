<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Übergebener Inventarartikel; Name und Kategorie wie zum Zeitpunkt der Übergabe. */
#[Fillable(['protocol_id', 'inventory_item_id', 'category_id', 'category_name', 'item_name', 'sort_order'])]
class HandoverProtocolItem extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<HandoverProtocol, $this> */
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(HandoverProtocol::class, 'protocol_id');
    }
}
