<?php

namespace App\Access;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Regeln beim Speichern eines Benutzers, die über die Policy hinausgehen:
 * Die Admin-Rolle vergeben oder entziehen nur Admins, und der letzte aktive
 * Admin muss aktiv bleiben und die Rolle behalten.
 */
final class AccountSafety
{
    /**
     * @param  array<int|string>  $roleIds  ausgewählte Rollen
     * @return string|null  Grund, warum das Speichern abgelehnt wird
     */
    public static function violation(?User $target, array $roleIds, bool $active): ?string
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

        return null;
    }
}
