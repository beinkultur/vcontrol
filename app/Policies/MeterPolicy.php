<?php

namespace App\Policies;

use App\Access\Area;
use App\Access\Module;
use App\Models\Meter;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Zähler (Stammdaten › Zähler): Welche Zähler es gibt, mit Einheit und ob je
 * Event abgelesen wird, legt fest, wer die Venue verwaltet (Bereich
 * „Stammdaten“, auf ipa die Admins). Sortieren wie die Räume; löschen nur,
 * solange kein Stand erfasst ist – sonst deaktivieren. Nur, wenn das Modul
 * Betrieb oder der Bereich Zählerstände frei ist (17.11.2026).
 */
class MeterPolicy extends AreaPolicy
{
    protected function area(): Area
    {
        return Area::AdminStammdaten;
    }

    /** Zähler wirken nur über Betrieb (Stände je Event) oder Zählerstände (/zaehler) – sonst gibt es nichts zu pflegen */
    public function viewAny(User $user): bool
    {
        return self::inUse() && parent::viewAny($user);
    }

    public function create(User $user): bool
    {
        return self::inUse() && parent::create($user);
    }

    private static function inUse(): bool
    {
        return Module::Operations->isEnabled() || !Area::Zaehler->isDisabled();
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
