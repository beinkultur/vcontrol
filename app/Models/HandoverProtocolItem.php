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

    /** Für das Änderungsprotokoll: das Event des Kopfes */
    public function auditEventId(): ?int
    {
        $eventId = HandoverProtocol::query()->whereKey($this->getAttributes()['protocol_id'] ?? null)->value('event_id');

        return $eventId === null ? null : (int) $eventId;
    }
}
