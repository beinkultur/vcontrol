<?php

namespace App\Filament\Resources\Roles\Pages\Concerns;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Beim Speichern einer Rolle: Wer kein Admin ist, gibt ihr höchstens die
 * eigenen Stufen – sonst könnte sich jemand mit „Rollen & Rechte“ über die
 * eigene Rolle beliebige Rechte geben.
 */
trait GrantsOnlyOwnRights
{
    protected function haltIfGrantingMoreThanOwnRights(): void
    {
        $actor = Auth::user();
        $areas = (array) ($this->data['permissions'] ?? []);
        $calendars = (array) ($this->data['calendar_permissions'] ?? []);

        if ($actor instanceof User && !$actor->access()->covers($areas, $calendars)) {
            Notification::make()
                ->danger()
                ->title('Mehr Rechte als die eigenen vergibt nur, wer sie selbst hat.')
                ->body('Bitte die Stufen auf die eigenen Rechte begrenzen oder einen Admin fragen.')
                ->send();
            $this->halt();
        }
    }
}
