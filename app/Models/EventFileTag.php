<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Art einer Event-Datei (Running Order, Plan, Riggingplot …); wird archiviert statt gelöscht, sobald benutzt. */
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
     * Wählbar: aktive Tags plus der aktuelle, falls er inzwischen archiviert ist.
     *
     * @return array<int, string>
     */
    public static function options(?int $current = null): array
    {
        return self::query()
            ->where(fn (Builder $query): Builder => $query->where('is_archived', false)->orWhere('id', $current))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
