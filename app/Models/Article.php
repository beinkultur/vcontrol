<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Artikel für Bestellscheine mit Einheit und Preis. Wird deaktiviert statt gelöscht, sobald bestellt. */
#[Fillable(['category_id', 'name', 'short_name', 'unit', 'price', 'is_active'])]
class Article extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ArticleCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'category_id');
    }

    /**
     * Auswahl nach Kategorie gruppiert, mit Einheit und Preis.
     *
     * @return array<string, array<int, string>>
     */
    public static function groupedOptions(): array
    {
        return self::query()
            ->with('category')
            ->where('is_active', true)
            ->whereHas('category', fn (Builder $query): Builder => $query->where('is_active', true))
            ->get()
            ->sortBy(fn (Article $article): string => sprintf('%05d %s', $article->category->sort_order, mb_strtolower($article->name)))
            ->groupBy(fn (Article $article): string => $article->category->name)
            ->map(fn ($articles) => $articles->mapWithKeys(fn (Article $article): array => [$article->id => $article->label()])->all())
            ->all();
    }

    public function label(): string
    {
        return collect([$this->name, $this->unit, $this->price !== null ? number_format((float) $this->price, 2, ',', '.') . ' €' : null])
            ->filter()
            ->implode(' · ');
    }
}
