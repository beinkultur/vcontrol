<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Position eines Bestellscheins; Name, Einheit und Preis wie zum Zeitpunkt der Bestellung. */
#[Fillable(['order_slip_id', 'article_id', 'category_id', 'category_name', 'article_name', 'unit', 'unit_price', 'quantity', 'line_total', 'sort_order'])]
class OrderSlipItem extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<OrderSlip, $this> */
    public function slip(): BelongsTo
    {
        return $this->belongsTo(OrderSlip::class, 'order_slip_id');
    }

    /** Menge ohne überflüssige Nachkommastellen: 2,00 → 2, 1,50 → 1,5 */
    public function quantityLabel(): string
    {
        return rtrim(rtrim(number_format((float) $this->quantity, 2, ',', ''), '0'), ',');
    }

    /** Für das Änderungsprotokoll: das Event des Kopfes */
    public function auditEventId(): ?int
    {
        $eventId = OrderSlip::query()->whereKey($this->getAttributes()['order_slip_id'] ?? null)->value('event_id');

        return $eventId === null ? null : (int) $eventId;
    }
}
