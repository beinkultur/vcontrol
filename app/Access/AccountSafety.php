<?php

namespace App\Access;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Regeln beim Speichern eines Benutzers, die über die Policy hinausgehen:
 * Die Admin-Rolle vergeben oder entziehen nur Admins, die System-Admin-Rolle
 * nur System-Admins. Der letzte aktive Admin und der letzte aktive System-Admin
 * müssen aktiv bleiben und ihre Rolle behalten. Außerdem vergibt niemand mehr
 * Rechte, als er selbst hat – weder über Rollen noch über Kalender-Rechte am
 * Konto (auch nicht sich selbst).
 */
final class AccountSafety
{
    /**
     * @param  array<int|string>  $roleIds  ausgewählte Rollen
     * @param  array<string, mixed>  $calendars  Kalender-Rechte am Konto selbst
     * @return string|null  Grund, warum das Speichern abgelehnt wird
     */
    public static function violation(?User $target, array $roleIds, bool $active, array $calendars = []): ?string
    {
        $actor = Auth::user();
        $actorAccess = $actor instanceof User ? $actor->access() : null;
        $roles = Role::query()->whereIn('id', $roleIds)->get();
        $grantsSystem = $roles->contains(fn (Role $role): bool => $role->is_system_admin);
        $grantsSuper = $roles->contains(fn (Role $role): bool => $role->is_super || $role->is_system_admin);

        if ($grantsSystem !== ($target?->access()->isSystemAdmin() ?? false) && !($actorAccess?->isSystemAdmin() ?? false)) {
            return 'Die System-Admin-Rolle vergeben oder entziehen nur System-Admins.';
        }

        if ($grantsSuper !== ($target?->access()->isSuper() ?? false) && !($actorAccess?->isSuper() ?? false)) {
            return 'Die Admin-Rolle dürfen nur Admins vergeben oder entziehen.';
        }

        if ($target !== null && $target->isLastSystemAdmin() && (!$grantsSystem || !$active)) {
            return 'Der letzte aktive System-Admin muss aktiv bleiben und die System-Admin-Rolle behalten.';
        }

        if ($target !== null && $target->isLastSuper() && (!$grantsSuper || !$active)) {
            return 'Der letzte aktive Admin muss aktiv bleiben und die Admin-Rolle behalten.';
        }

        if ($actorAccess !== null && !$actorAccess->isSuper()) {
            foreach ($roles as $role) {
                if (!$role->is_super && !$actorAccess->covers($role->permissions ?? [], $role->calendar_permissions ?? [])) {
                    return "Die Rolle „{$role->name}“ hat mehr Rechte als die eigenen – vergeben kann sie nur, wer diese Rechte selbst hat.";
                }
            }
            if (!$actorAccess->covers([], $calendars)) {
                return 'Kalender-Rechte über die eigenen hinaus vergibt nur, wer sie selbst hat.';
            }
        }

        return null;
    }
}
