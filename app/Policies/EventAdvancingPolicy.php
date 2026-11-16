<?php

namespace App\Policies;

use App\Access\Area;
use App\Access\Module;
use App\Models\User;

/**
 * Advancing sieht, wer Events lesen darf; starten, vorbelegen, versenden, sperren
 * und abschließen, wer Events bearbeiten darf. Ohne das Modul gar nicht.
 * Gelöscht wird nichts – das Advancing bleibt als Nachweis am Event.
 */
class EventAdvancingPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::Events;
    }

    public function viewAny(User $user): bool
    {
        return Module::Advancing->isEnabled() && parent::viewAny($user);
    }

    public function create(User $user): bool
    {
        return Module::Advancing->isEnabled() && parent::create($user);
    }
}
