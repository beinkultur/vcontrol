<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\Article;
use App\Models\OrderSlipItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Artikel pflegt, wer das Inventar pflegt; gelöscht wird nur ein nie bestellter Artikel. */
class ArticlePolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminInventar;
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->access()->canEdit($this->area())
            && $record instanceof Article
            && !OrderSlipItem::query()->where('article_id', $record->getKey())->exists();
    }
}
