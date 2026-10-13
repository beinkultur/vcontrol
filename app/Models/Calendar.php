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

    /**
     * Reihenfolge überall (Leiste im Kalender, Verwaltung, Rechte): Events,
     * Anfragen, Persönlich, danach alphabetisch – Vorgabe vom 13.10.2026, ersetzt
     * die frühere Reihenfolge von Hand (sort_order bleibt ungenutzt stehen).
     *
     * @param Builder<self> $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $key = $query->getQuery()->getGrammar()->wrap('key');
        $query->orderByRaw("case {$key} when ? then 0 when ? then 1 when ? then 2 else 3 end", [self::EVENTS, self::INQUIRIES, self::PERSONAL])
            ->orderBy('name');
    }
}
