<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\EventFileTag;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Datei-Tags pflegt wie in der PHP-Version (/dateien/tags), wer Events
 * bearbeiten darf. Gelöscht wird nur ein unbenutzter Tag, sonst archiviert.
 */
class EventFileTagPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::Events;
    }

    public function viewAny(User $user): bool
    {
        return $user->access()->canEdit($this->area());
    }

    public function reorder(User $user): bool
    {
        return $user->access()->canEdit($this->area());
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->access()->canEdit($this->area())
            && $record instanceof EventFileTag
            && !$record->files()->exists();
    }
}
