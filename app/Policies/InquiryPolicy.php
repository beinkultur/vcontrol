<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Anfragen (Optionen): lesen und anlegen nach dem Bereich „Anfragen“. Ändern,
 * bestätigen und zurückziehen nur, solange die Anfrage offen ist; bestätigen
 * legt ein Event an und verlangt deshalb auch „Events bearbeiten“.
 */
class InquiryPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::Anfragen;
    }

    public function update(User $user, Model $record): bool
    {
        return $record instanceof Inquiry && $record->isOpen() && parent::update($user, $record);
    }

    public function confirm(User $user, Inquiry $inquiry): bool
    {
        return $this->update($user, $inquiry) && $user->access()->canEdit(Area::Events);
    }

    public function withdraw(User $user, Inquiry $inquiry): bool
    {
        return $this->update($user, $inquiry);
    }
}
