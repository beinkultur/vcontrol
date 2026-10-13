<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Preisliste der Halle (Modul Preislisten & Costing), etwa „Konzert/Comedy
 * 2025-09“, mit ihren Kostenpunkten. Events wählen eine unter Buchung › Daten,
 * ein Costing übernimmt daraus Kostenpunkte, Preise und Vorgabe-Faktoren.
 * Kopf (intro) und Anmerkungen (notes) stehen im Costing-PDF über und unter den
 * Kostenpunkten.
 */
#[Fillable(['name', 'valid_from', 'intro', 'notes', 'is_active'])]
class PriceList extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @return HasMany<Costing, $this> */
    public function costings(): HasMany
    {
        return $this->hasMany(Costing::class);
    }

    /** „Konzert/Comedy 2025-09 (gültig ab 01.09.2025)“ */
    public function label(): string
    {
        return $this->name . ($this->valid_from !== null ? ' (gültig ab ' . $this->valid_from->format('d.m.Y') . ')' : '');
    }

    /**
     * Zur Auswahl am Event: die aktiven, neueste zuerst, dazu eine schon gewählte.
     *
     * @return array<int, string>
     */
    public static function choices(?int $current = null): array
    {
        return self::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->when($current !== null, fn (Builder $query) => $query->orWhere('id', $current)))
            ->orderByDesc('valid_from')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (self $list): array => [$list->id => $list->label()])
            ->all();
    }

    /** Kopie mit allen Kostenpunkten, etwa als Grundlage der nächsten Preisliste – inaktiv, bis sie fertig ist. */
    public function duplicate(string $name): self
    {
        // Ausdrücklich statt replicate(): Die Liste lädt Zählungen (items_count), die keine Spalten sind
        return DB::transaction(function () use ($name): self {
            $copy = self::create([
                'name' => $name,
                'valid_from' => $this->valid_from,
                'intro' => $this->intro,
                'notes' => $this->notes,
                'is_active' => false,
            ]);
            foreach ($this->items as $item) {
                $copy->items()->create($item->only(PriceListItem::COPIED));
            }

            return $copy;
        });
    }
}
