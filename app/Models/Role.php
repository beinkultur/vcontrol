<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Rolle mit Rechte-Matrix. permissions: Bereich => none|read|edit,
 * calendar_permissions: Kalender-Schlüssel => none|read|edit.
 */
#[Fillable(['slug', 'name', 'description', 'is_system', 'is_super', 'sort_order', 'permissions', 'calendar_permissions'])]
class Role extends Model
{
    /** Veranstalter und Dienstleister – sehen nur das Extern-Portal. */
    public const EXTERN = 'extern';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_super' => 'boolean',
            'sort_order' => 'integer',
            'permissions' => 'array',
            'calendar_permissions' => 'array',
        ];
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
