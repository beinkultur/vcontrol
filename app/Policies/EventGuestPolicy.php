<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Gäste pflegt, wer Events bearbeiten darf – einschließlich Löschen. */
class EventGuestPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::Events;
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->access()->canEdit($this->area());
    }

    public function deleteAny(User $user): bool
    {
        return $user->access()->canEdit($this->area());
    }
}
