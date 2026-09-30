<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Einstellung dieser Halle als Schlüssel/Wert. */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const VENUE_NAME = 'venue_name';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public static function lookup(string $key, ?string $default = null): ?string
    {
        return self::query()->find($key)?->value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
