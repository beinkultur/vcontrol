<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\MeterReading;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Zählerstände: sehen und frei erfassen nach dem Bereich „Zählerstände“.
 * Stände eines Events pflegt das Event (Durchführung › Betrieb, Recht der
 * Events) – auf der Seite Zählerstände stehen sie nur zur Ansicht. Freie
 * Ablesungen dürfen Bearbeiter ändern und löschen.
 */
class MeterReadingPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::Zaehler;
    }

    public function update(User $user, Model $record): bool
    {
        return $record instanceof MeterReading
            && !$record->isFromEvent()
            && $this->create($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }
}
