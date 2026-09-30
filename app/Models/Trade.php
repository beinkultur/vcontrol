<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Gewerk – Dienstleister der Halle. */
#[Fillable(['short_name', 'name', 'categories', 'email', 'phone', 'address1', 'address2', 'zip', 'city', 'is_archived'])]
class Trade extends Model
{
    /**
     * Vorschläge für die Leistungsbereiche. Freie Werte sind erlaubt – der
     * Bestand enthält auch Bereiche, die hier nicht stehen.
     */
    public const CATEGORY_SUGGESTIONS = [
        'Catering', 'Personal', 'Produktion', 'Reinigung', 'Sanitäter', 'SFX',
        'Sicherheit', 'Umbau', 'VA-Technik', 'Verkehr', 'VfV',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'is_archived' => 'boolean',
        ];
    }

    public function displayName(): string
    {
        return $this->short_name ?: $this->name;
    }
}
