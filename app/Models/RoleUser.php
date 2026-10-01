<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Rollen-Zuordnung eines Benutzers. Als eigenes Pivot-Modell, damit Zuweisen und
 * Entziehen von Rollen im Änderungsprotokoll landen.
 */
class RoleUser extends Pivot
{
    protected $table = 'role_user';

    public function auditLabel(): string
    {
        $user = User::query()->find($this->getAttribute('user_id'));
        $role = Role::query()->find($this->getAttribute('role_id'));

        return trim(($user?->getFilamentName() ?? 'Benutzer') . ' · ' . ($role->name ?? 'Rolle'));
    }
}
