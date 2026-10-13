<?php

namespace App\Models;

use App\Models\Concerns\StampsAuthor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Costing eines Events (Modul Preislisten & Costing): Kostenaufstellung auf
 * Grundlage einer Preisliste, die der Eventmanager anpasst und als PDF an den
 * Veranstalter schickt (App\Support\Costings). Je Event in Fassungen: Eine
 * verschickte Fassung bleibt, wie sie ist – Änderungen gehen als neue Fassung.
 * Alle Preise netto, die Mehrwertsteuer kommt im PDF dazu.
 */
#[Fillable(['event_id', 'price_list_id', 'version', 'note', 'vat_rate', 'sent_at', 'sent_to', 'sent_by_name'])]
class Costing extends Model
{
    use StampsAuthor;

    /** Mehrwertsteuer in Prozent für neue Costings */
    public const VAT_RATE = 19.0;

    /** Abschnitt für Posten ohne eigenen */
    public const OTHER_SECTION = 'Weitere Posten';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'vat_rate' => 'float',
            'sent_at' => 'datetime',
            'sent_to' => 'array',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return HasMany<CostingItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CostingItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    /** „Fassung 2“ */
    public function label(): string
    {
        return 'Fassung ' . $this->version;
    }

    public function statusLabel(): string
    {
        return $this->isSent() ? 'verschickt am ' . $this->sent_at->format('d.m.Y') : 'Entwurf';
    }

    /** Summe netto der eingeschalteten Posten */
    public function net(): float
    {
        return round($this->items->sum(fn (CostingItem $item): float => $item->isActive() ? ($item->amount() ?? 0.0) : 0.0), 2);
    }

    public function vat(): float
    {
        return self::percentOf($this->net(), $this->vat_rate);
    }

    /**
     * Prozent eines Betrags, auf den Cent gerundet. Gerechnet in Cent: 1.118,245 €
     * wären als Gleitkommazahl knapp darunter und würden abgerundet.
     */
    public static function percentOf(float $amount, float $rate): float
    {
        return round(round($amount * 100) * $rate / 100) / 100;
    }

    public function gross(): float
    {
        return round($this->net() + $this->vat(), 2);
    }

    /**
     * Was ins PDF kommt: die eingeschalteten Posten, auch mit Faktor 0, nach den
     * Abschnitten der Preisliste in ihrer Reihenfolge; ergänzte Posten am Ende.
     *
     * @return array<string, list<CostingItem>>
     */
    public function printedSections(): array
    {
        $sections = [];
        foreach ($this->items as $item) {
            if ($item->isActive()) {
                $sections[filled($item->section) ? (string) $item->section : self::OTHER_SECTION][] = $item;
            }
        }
        // Ergänzte Posten ohne Abschnitt ans Ende
        if (isset($sections[self::OTHER_SECTION])) {
            $other = $sections[self::OTHER_SECTION];
            unset($sections[self::OTHER_SECTION]);
            $sections[self::OTHER_SECTION] = $other;
        }

        return $sections;
    }

    /** „Costing Konzert Nord 2026-10-20 Fassung 2.pdf“ */
    public function fileName(): string
    {
        $title = trim((string) preg_replace(['/[^\pL\pN .,&()+-]+/u', '/\s+/'], ' ', (string) $this->event?->title));

        return trim(implode(' ', array_filter(['Costing', $title, $this->event?->starts_at?->format('Y-m-d'), $this->label()]))) . '.pdf';
    }

    /**
     * Neues Costing aus der Preisliste: alle Kostenpunkte eingeschaltet, mit ihren
     * Vorgabe-Faktoren, Posten „Faktor = PAX erwartet“ mit den erwarteten PAX
     * des Events. Ohne Preisliste ein leeres Costing zum Ausfüllen.
     */
    public static function createFor(Event $event, ?PriceList $list): self
    {
        return DB::transaction(function () use ($event, $list): self {
            $costing = $event->costings()->create([
                'price_list_id' => $list?->id,
                'version' => self::nextVersion($event),
                'vat_rate' => self::VAT_RATE,
            ]);
            foreach ($list?->items ?? [] as $index => $item) {
                $costing->items()->create([
                    'price_list_item_id' => $item->id,
                    'section' => $item->section,
                    'name' => $item->name,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'price' => $item->price,
                    'minimum' => $item->minimum,
                    'factor' => $item->factor_from_pax && $event->pax_expected > 0 ? $event->pax_expected : ($item->default_factor ?? 0),
                    'fixed_price' => $item->price !== null,
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ]);
            }

            return $costing;
        });
    }

    /** Neue Fassung als Entwurf: Kopie mit allen Posten und der Anmerkung. */
    public function duplicate(): self
    {
        return DB::transaction(function (): self {
            $copy = $this->event->costings()->create([
                'price_list_id' => $this->price_list_id,
                'version' => self::nextVersion($this->event),
                'note' => $this->note,
                'vat_rate' => $this->vat_rate,
            ]);
            foreach ($this->items as $item) {
                $copy->items()->create($item->only(CostingItem::COPIED));
            }

            return $copy;
        });
    }

    /** Ohne die Sortierung der Beziehung – MySQL lehnt ORDER BY neben MAX() ab (only_full_group_by) */
    private static function nextVersion(Event $event): int
    {
        return (int) self::query()->where('event_id', $event->getKey())->lockForUpdate()->max('version') + 1;
    }
}
