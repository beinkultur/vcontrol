<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Fragenkatalog fürs Advancing (Modul Advancing): Abschnitte mit Infotexten und
 * Fragen als Struktur (App\Support\AdvancingCatalog). Eingespielt per
 * `php artisan vc:advancing-catalog import`; je Event wird beim Start eine Kopie
 * gezogen (EventAdvancing), spätere Änderungen am Katalog wirken dort nicht.
 */
#[Fillable(['name', 'intro', 'structure', 'is_active', 'sort_order'])]
class AdvancingTemplate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'structure' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    /** @return array<string, array<string, mixed>> Fragen nach Schlüssel, ohne Infotexte */
    public function questions(): array
    {
        return \App\Support\AdvancingCatalog::questions((array) $this->structure);
    }
}
