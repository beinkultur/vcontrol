<?php

namespace App\Access;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Regeln beim Speichern eines Benutzers, die über die Policy hinausgehen:
 * Die Admin-Rolle vergeben oder entziehen nur Admins, und der letzte aktive
 * Admin muss aktiv bleiben und die Rolle behalten. Außerdem vergibt niemand
 * mehr Rechte, als er selbst hat – weder über Rollen noch über Kalender-Rechte
 * am Konto (auch nicht sich selbst).
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
        $superIds = Role::query()->where('is_super', true)->pluck('id')->map(fn (mixed $id): int => (int) $id);
        $grantsSuper = collect($roleIds)->map(fn (mixed $id): int => (int) $id)->intersect($superIds)->isNotEmpty();
        $hadSuper = $target?->access()->isSuper() ?? false;

        if ($grantsSuper !== $hadSuper && !($actor instanceof User && $actor->access()->isSuper())) {
            return 'Die Admin-Rolle dürfen nur Admins vergeben oder entziehen.';
        }

        if ($target !== null && $target->isLastSuper() && (!$grantsSuper || !$active)) {
            return 'Der letzte aktive Admin muss aktiv bleiben und die Admin-Rolle behalten.';
        }

        if ($actor instanceof User && !$actor->access()->isSuper()) {
            foreach (Role::query()->whereIn('id', $roleIds)->where('is_super', false)->get() as $role) {
                if (!$actor->access()->covers($role->permissions ?? [], $role->calendar_permissions ?? [])) {
                    return "Die Rolle „{$role->name}“ hat mehr Rechte als die eigenen – vergeben kann sie nur, wer diese Rechte selbst hat.";
                }
            }
            if (!$actor->access()->covers([], $calendars)) {
                return 'Kalender-Rechte über die eigenen hinaus vergibt nur, wer sie selbst hat.';
            }
        }

        return null;
    }
}
