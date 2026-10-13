<?php

namespace App\Policies;

use App\Access\Area;
use App\Access\Module;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Gäste pflegt, wer Events bearbeiten darf – einschließlich Löschen. Nur mit dem Modul Gästeliste. */
class EventGuestPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::Events;
    }

    public function viewAny(User $user): bool
    {
        return Module::GuestList->isEnabled() && parent::viewAny($user);
    }

    public function create(User $user): bool
    {
        return Module::GuestList->isEnabled() && parent::create($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->create($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }
}
