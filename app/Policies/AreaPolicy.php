<?php

namespace App\Policies;

use App\Access\Area;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Grundregel für alles, was an einem Bereich hängt: Lesen braucht die Stufe
 * „Nur lesen“, Anlegen und Ändern „Bearbeiten“. Löschen ist standardmäßig aus
 * und wird nur dort erlaubt, wo es fachlich sinnvoll ist.
 *
 * Alle Methoden, die Filament abfragt, sind hier ausdrücklich definiert – das
 * Panel läuft im strikten Modus und bricht bei fehlenden Methoden ab.
 */
abstract class AreaPolicy
{
    abstract protected function area(): Area;

    public function viewAny(User $user): bool
    {
        return $user->access()->can($this->area());
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->access()->canEdit($this->area());
    }

    public function update(User $user, Model $record): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Model $record): bool
    {
        return false;
    }
}
