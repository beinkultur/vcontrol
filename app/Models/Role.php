<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Rolle mit Rechte-Matrix. permissions: Bereich => none|read|edit,
 * calendar_permissions: Kalender-Schlüssel => none|read|edit.
 *
 * Zwei Stufen Vollzugriff: is_super ist der Admin der Halle (alle Bereiche und
 * Kalender), is_system_admin der Betreiber von VenueControl – dazu Module,
 * Rollen & Rechte und die System-Admin-Konten. is_system_admin ist bewusst
 * nicht fillable; die Rolle legt nur systemAdmin() an.
 */
#[Fillable(['slug', 'name', 'description', 'is_system', 'is_super', 'sort_order', 'permissions', 'calendar_permissions'])]
class Role extends Model
{
    /** Veranstalter und Dienstleister – sehen nur das Extern-Portal. */
    public const EXTERN = 'extern';

    public const SYSTEM_ADMIN = 'systemadmin';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_super' => 'boolean',
            'is_system_admin' => 'boolean',
            'sort_order' => 'integer',
            'permissions' => 'array',
            'calendar_permissions' => 'array',
        ];
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(RoleUser::class);
    }

    /** Die System-Admin-Rolle; legt sie beim ersten Aufruf an (vc:system-admin). */
    public static function systemAdmin(): self
    {
        $role = self::query()->where('is_system_admin', true)->orderBy('id')->first();
        if ($role !== null) {
            return $role;
        }

        $role = new self([
            'slug' => self::SYSTEM_ADMIN,
            'name' => 'System-Admin',
            'description' => 'Betreiber von VenueControl: alles, auch Module, Rollen & Rechte',
            'is_system' => true,
            'is_super' => true,
            'sort_order' => 0,
            'permissions' => [],
            'calendar_permissions' => [],
        ]);
        $role->forceFill(['is_system_admin' => true])->save();

        return $role;
    }

    /** Die System-Admin-Rolle sehen nur System-Admins (Rollenliste, Auswahl, Filter). */
    public function scopeVisibleTo(Builder $query, ?User $viewer): void
    {
        if (!($viewer?->access()->isSystemAdmin() ?? false)) {
            $query->where('is_system_admin', false);
        }
    }
}
