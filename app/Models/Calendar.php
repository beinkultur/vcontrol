<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kalender-Ebene (Events, Anfragen, Wartung …) mit eigener Farbe und eigenen Rechten. */
#[Fillable(['key', 'name', 'color', 'freitermin_status', 'is_system', 'sort_order'])]
class Calendar extends Model
{
    /** Persönlicher Kalender – lesbar für jeden, der einen Kalender sehen darf. */
    public const PERSONAL = 'personal';

    /** Ebenen, an denen nur die Farbe änderbar ist – der Code hängt an ihnen. */
    public const LOCKED = ['events', 'anfragen'];

    public const EVENTS = 'events';

    public const INQUIRIES = 'anfragen';

    /**
     * Ebenen ohne eigene Einträge: ihr Inhalt kommt aus Events, Anfragen und dem
     * Personal (App\Support\CalendarItems). Auf allen anderen (Wartung, Technik …)
     * legt an, wer die Ebene bearbeiten darf.
     */
    public const DERIVED = [self::EVENTS, self::INQUIRIES, self::PERSONAL];

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

    public function allowsEntries(): bool
    {
        return !in_array($this->key, self::DERIVED, true);
    }

    /** @return HasMany<CalendarEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(CalendarEntry::class, 'calendar_key', 'key');
    }

    public function isLocked(): bool
    {
        return in_array($this->key, self::LOCKED, true);
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
