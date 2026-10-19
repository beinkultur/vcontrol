<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Art einer Event-Datei (Running Order, Plan, Riggingplot …); wird archiviert
 * statt gelöscht, sobald benutzt. Überall alphabetisch sortiert (seit
 * 19.10.2026, vorher von Hand; sort_order bleibt ungenutzt stehen).
 */
#[Fillable(['name', 'sort_order', 'is_archived'])]
class EventFileTag extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_archived' => 'boolean',
        ];
    }

    /** @return HasMany<EventFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(EventFile::class, 'tag_id');
    }

    /**
     * Wählbar: aktive Tags plus der aktuelle, falls er inzwischen archiviert ist –
     * alphabetisch, ohne Rücksicht auf Groß-/Kleinschreibung und Umlaute.
     *
     * @return array<int, string>
     */
    public static function options(?int $current = null): array
    {
        return self::query()
            ->where(fn (Builder $query): Builder => $query->where('is_archived', false)->orWhere('id', $current))
            ->get(['id', 'name'])
            ->sortBy(fn (self $tag): string => self::sortKey($tag->name), SORT_NATURAL)
            ->pluck('name', 'id')
            ->all();
    }

    /** Sortierschlüssel: „Ä“ wie „A“, klein wie groß */
    public static function sortKey(string $name): string
    {
        return Str::lower(Str::ascii($name));
    }
}
