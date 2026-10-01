<?php

namespace App\Models;

use App\Models\Concerns\StampsAuthor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Tageszugangscode wie in der PHP-Version (AccessCodeRepository): gespeichert
 * wird nur der Code, das ▲ davor steht nur in der Anzeige.
 */
#[Fillable(['code', 'valid_from', 'valid_on'])]
class AccessCode extends Model
{
    use StampsAuthor;

    /** Muss am Zugangssystem vor dem Code eingegeben werden. */
    public const PREFIX = '▲';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_on' => 'date',
        ];
    }

    public function display(): string
    {
        return self::PREFIX . $this->code;
    }

    /** Der jüngste Code, dessen Gültigkeit begonnen hat. */
    public static function current(): ?self
    {
        return self::query()->where('valid_from', '<=', now())->orderByDesc('valid_from')->first();
    }

    public static function forDate(string $date): ?self
    {
        return self::query()->whereDate('valid_on', $date)->first();
    }

    /** @return Collection<int, self> ab heute */
    public static function upcoming(int $limit = 30): Collection
    {
        return self::query()->whereDate('valid_on', '>=', today())->orderBy('valid_on')->limit($limit)->get();
    }
}
