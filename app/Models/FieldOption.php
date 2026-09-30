<?php

namespace App\Models;

use App\Enums\OptionField;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Auswahlwert eines Event-Felds. */
#[Fillable(['field_key', 'value', 'parent_value', 'sort_order', 'is_active'])]
class FieldOption extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_key' => OptionField::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeForField(Builder $query, OptionField $field): void
    {
        $query->where('field_key', $field->value)->orderBy('sort_order')->orderBy('value');
    }

    /**
     * Aktive Werte eines Felds als Auswahl (Wert => Wert).
     *
     * @return array<string, string>
     */
    public static function choices(OptionField $field): array
    {
        return self::query()->forField($field)->where('is_active', true)
            ->pluck('value', 'value')->all();
    }
}
