<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Kontakte: Bereich „Kontakte“ (Schlüssel admin_mitarbeiter wie bisher die
 * Mitarbeiter, damit die Rechte der Rollen bleiben). Archivieren; löschen nur,
 * solange kein Event auf den Kontakt verweist.
 */
class ContactPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminMitarbeiter;
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Contact
            && $user->access()->canEdit($this->area())
            && !$record->isInUse();
    }
}
