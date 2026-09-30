<?php

namespace App\Filament\Support;

use App\Enums\OptionField;
use App\Models\FieldOption;

/** Auswahlwerte eines Event-Felds aus den Feldoptionen. */
final class OptionChoices
{
    /**
     * Aktive Werte plus die schon gespeicherten – sonst ginge ein Wert, den es
     * als Option nicht mehr gibt, beim nächsten Speichern verloren.
     *
     * @return array<string, string>
     */
    public static function for(OptionField $field, mixed $current = null, ?string $parent = null): array
    {
        $query = FieldOption::query()->forField($field)->where('is_active', true);
        if ($field->parent() !== null) {
            $query->where('parent_value', $parent);
        }

        $options = $query->pluck('value', 'value')->all();
        foreach ((array) $current as $value) {
            if (is_string($value) && $value !== '') {
                $options[$value] ??= $value;
            }
        }

        return $options;
    }
}
