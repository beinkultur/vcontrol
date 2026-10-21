<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * Betriebsdaten der Durchführung: Bus-Strom (Anzahl Anschlüsse), Haus-Delay,
 * Personenlift (seit 21.10.2026).
 * Dazu Altdaten aus AppSheet (Zähler-Verweise, Backstages/Büros als Text). Die
 * Zählerstände je Event stehen seit 15.10.2026 in meter_readings (Meter).
 */
#[Fillable(['power_start_ref', 'power_end_ref', 'backstages', 'offices', 'bus_power', 'house_delay', 'person_lift'])]
class EventOperation extends EventDetail
{
    public const MAX_BUS_POWER = 5;

    protected $table = 'event_operations';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bus_power' => 'integer',
            'house_delay' => 'boolean',
            'person_lift' => 'boolean',
        ];
    }
}
