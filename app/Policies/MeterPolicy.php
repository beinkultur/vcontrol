<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\Meter;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Zähler (Stammdaten › Zähler): Welche Zähler es gibt, mit Einheit und ob je
 * Event abgelesen wird, legt fest, wer die Venue verwaltet (Bereich
 * „Stammdaten“, auf ipa die Admins). Sortieren wie die Räume; löschen nur,
 * solange kein Stand erfasst ist – sonst deaktivieren.
 */
class MeterPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminStammdaten;
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Meter
            && $user->access()->canEdit($this->area())
            && !$record->readings()->exists();
    }

    public function reorder(User $user): bool
    {
        return $user->access()->canEdit($this->area());
    }
}
