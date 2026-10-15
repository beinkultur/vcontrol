<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Zähler der Venue (Stammdaten › Zähler): Strom, Wasser, Fernwärme … mit
 * Einheit. Mit $per_event wird er je Event abgelesen (Stand Anfang/Ende in
 * Durchführung › Betrieb), sonst nur frei auf der Seite Zählerstände. Archiviert
 * (inaktiv) bleibt er mit seinen Ständen erhalten.
 */
#[Fillable(['name', 'unit', 'number', 'location', 'per_event', 'is_active', 'sort_order'])]
class Meter extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'per_event' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<MeterReading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Je Event abgelesen und aktiv: steht im Event unter Durchführung › Betrieb.
     *
     * @return Collection<int, self>
     */
    public static function forEvents(): Collection
    {
        return self::query()->where('per_event', true)->where('is_active', true)->ordered()->get();
    }
}
