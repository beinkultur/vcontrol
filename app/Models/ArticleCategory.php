<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kategorie der Bestell-Artikel (Getränke, Bühne, Technik …). */
#[Fillable(['name', 'sort_order', 'is_active'])]
class ArticleCategory extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Article, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'category_id');
    }
}
