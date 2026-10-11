<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Mitarbeiter der Halle, z. B. Veranstaltungsleitung (VL) oder VfV. Archivierte
 * stehen nicht mehr zur Auswahl, an bisherigen Events bleiben sie stehen.
 */
#[Fillable(['first_name', 'last_name', 'initials', 'phone', 'email', 'positions', 'is_archived'])]
class Employee extends Model
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

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    /** Hängt an einem Event oder einem Benutzerkonto – dann archivieren statt löschen. */
    public function isInUse(): bool
    {
        return EventAssignment::query()->where('assignee_type', 'employee')->where('assignee_id', $this->getKey())->exists()
            || User::query()->where('employee_id', $this->getKey())->exists();
    }
}
