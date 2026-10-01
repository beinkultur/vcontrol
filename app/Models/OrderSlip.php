<?php

namespace App\Models;

use App\Models\Concerns\StampsAuthor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bestellschein eines Events wie in der PHP-Version: „Bestellt von“ (wer
 * bestellt hat), „Bei wem“ (wer die Bestellung aufgenommen hat, created_by_name),
 * Positionen mit Menge und Preis, optional mit Unterschrift; „abgerechnet“ setzt
 * die Buchhaltung.
 */
#[Fillable(['event_id', 'ordered_from', 'ordered_at', 'signature', 'comment', 'is_settled'])]
class OrderSlip extends Model
{
    use StampsAuthor;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'is_settled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<OrderSlipItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderSlipItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function total(): float
    {
        return (float) $this->items->sum('line_total');
    }

    public static function money(float $amount): string
    {
        return number_format($amount, 2, ',', '.') . ' €';
    }
}
