<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kostenpunkt einer Preisliste: Preis je Einheit (leer: nach Aufwand), bei der
 * Einheit „%“ ein Zuschlag in Prozent. Einheiten pflegt der Admin als
 * Feldoption „Einheit (Preislisten)“ (OptionField::CostUnit). Mindestbetrag
 * etwa für die Facility-Fee („mind. 2.500 €“). Vorgabe-Faktor, „Faktor = PAX
 * erwartet“ und „Im Costing“ (default_active) bestimmen, was ein neues Costing
 * einträgt (Costing::createFor()) – ausgeschaltet etwa Alternativen.
 */
#[Fillable(['price_list_id', 'section', 'name', 'description', 'unit', 'price', 'minimum', 'default_factor', 'factor_from_pax', 'default_active', 'sort_order'])]
class PriceListItem extends Model
{
    /** Einheit eines Zuschlags: Preis in Prozent, Faktor im Costing ist der Betrag in €, auf den er gilt */
    public const PERCENT = '%';

    /** Was beim Kopieren der Preisliste mitkommt */
    public const COPIED = ['section', 'name', 'description', 'unit', 'price', 'minimum', 'default_factor', 'factor_from_pax', 'default_active', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'minimum' => 'float',
            'default_factor' => 'float',
            'factor_from_pax' => 'boolean',
            'default_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public static function isPercent(?string $unit): bool
    {
        return trim((string) $unit) === self::PERCENT;
    }
}
