<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Kontakt ohne Anmeldung (Stammdaten › Kontakte): eine Person wie „Gasparino
 * Duarte“ oder eine Funktion wie „Hallenmeister“. Lässt sich im Personal eines
 * Events eintragen (Auf-/Abschließen, mit passender Position auch House Rep.
 * oder VfV) und steht dann mit Telefon und E-Mail im Daysheet. Ersetzt seit
 * 14.10.2026 die Mitarbeiter der Stammdaten; Personen mit Konto tragen Position,
 * Kürzel und Telefon am Benutzer. Archivierte stehen nicht mehr zur Auswahl,
 * an bisherigen Events bleiben sie stehen.
 */
#[Fillable(['name', 'initials', 'phone', 'email', 'positions', 'note', 'is_archived'])]
class Contact extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'positions' => 'array',
            'is_archived' => 'boolean',
        ];
    }

    /** Steht im Personal eines Events – dann archivieren statt löschen. */
    public function isInUse(): bool
    {
        return EventAssignment::query()->where('assignee_type', 'contact')->where('assignee_id', $this->getKey())->exists();
    }
}
