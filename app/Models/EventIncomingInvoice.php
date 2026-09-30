<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Erwartete Eingangsrechnung eines Dienstleisters zu einem Event.
 * Schlüssel ist (event_id, invoice_key) – ändern per updateOrInsert, nicht per save().
 */
#[Fillable(['event_id', 'invoice_key', 'is_active', 'is_received', 'updated_by'])]
class EventIncomingInvoice extends Model
{
    /** Die sechs festen Positionen, wie in der PHP-Version. */
    public const SLOTS = [
        'gracework_umbau' => 'GraceWork (Umbau)',
        'zlaja_reinigung' => 'Zlaja (Reinigung)',
        'vfv' => 'VfVs',
        'cobra_hausrigg' => 'Cobra – Hausrigg',
        'cobra_hausdelay' => 'Cobra – Haus-Delay',
        'mobiliar_stuehle' => 'Mobiliar/Stühle',
    ];

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_received' => 'boolean',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function label(): string
    {
        return self::SLOTS[$this->invoice_key] ?? $this->invoice_key;
    }
}
