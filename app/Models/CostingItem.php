<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Posten eines Costings: aus der Preisliste übernommen (Preis fest, nur der
 * Faktor ändert sich) oder vom Eventmanager ergänzt. Betrag = Preis × Faktor,
 * bei der Einheit „%“ Prozent vom Faktor (dem Betrag in €, auf den der
 * Zuschlag gilt), mindestens der Mindestbetrag. Ohne Preis: nach Aufwand.
 */
#[Fillable(['costing_id', 'price_list_item_id', 'section', 'name', 'description', 'unit', 'price', 'minimum', 'factor', 'fixed_price', 'sort_order'])]
class CostingItem extends Model
{
    /** Was in eine neue Fassung mitkommt */
    public const COPIED = ['price_list_item_id', 'section', 'name', 'description', 'unit', 'price', 'minimum', 'factor', 'fixed_price', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'minimum' => 'float',
            'factor' => 'float',
            'fixed_price' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Costing, $this> */
    public function costing(): BelongsTo
    {
        return $this->belongsTo(Costing::class);
    }

    /** Änderungsprotokoll: gehört zum Event des Costings (App\Support\Audit) */
    public function auditEventId(): ?int
    {
        $eventId = Costing::query()->whereKey($this->getAttributes()['costing_id'] ?? null)->value('event_id');

        return $eventId === null ? null : (int) $eventId;
    }

    /** Betrag in €; null: nach Aufwand (ohne Preis) */
    public function amount(): ?float
    {
        return self::calculate($this->price, $this->factor, $this->unit, $this->minimum);
    }

    public function isPercent(): bool
    {
        return PriceListItem::isPercent($this->unit);
    }

    /** Auch für das Formular, das mit Zeichenketten rechnet */
    public static function calculate(mixed $price, mixed $factor, ?string $unit, mixed $minimum): ?float
    {
        if ($price === null || $price === '' || !is_numeric($price)) {
            return null;
        }
        $factor = is_numeric($factor) ? (float) $factor : 0.0;
        if ($factor === 0.0) {
            return 0.0;
        }

        $amount = PriceListItem::isPercent($unit) ? Costing::percentOf($factor, (float) $price) : round(round((float) $price * 100) * $factor) / 100;
        if (is_numeric($minimum) && $amount < (float) $minimum) {
            $amount = (float) $minimum;
        }

        return round($amount, 2);
    }
}
