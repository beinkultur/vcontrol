<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Kalender-Ebene (Events, Anfragen, Wartung …) mit eigener Farbe und eigenen Rechten. */
#[Fillable(['key', 'name', 'color', 'freitermin_status', 'is_system', 'sort_order'])]
class Calendar extends Model
{
    /** Persönlicher Kalender – lesbar für jeden, der einen Kalender sehen darf. */
    public const PERSONAL = 'personal';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
