<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Mitarbeiter der Halle, z. B. Veranstaltungsleitung (VL) oder VfV. */
#[Fillable(['first_name', 'last_name', 'initials', 'phone', 'email', 'positions'])]
class Employee extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'positions' => 'array',
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}
