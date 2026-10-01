<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\OperationsPolicy;
use Illuminate\Database\Eloquent\Model;

/** Checklisten pflegt, wer „Event-Operationen“ bearbeiten darf – einschließlich Löschen. */
class EventShowChecklistPolicy extends AreaPolicy
{
    use OperationsPolicy;

    public function delete(User $user, Model $record): bool
    {
        return $this->create($user);
    }
}
