<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/** Betriebsdaten: Strom, Backstages, Büros, Buspower, Haus-Delay. */
#[Fillable(['power_start_ref', 'power_end_ref', 'power_consumption', 'backstages', 'offices', 'bus_power', 'house_delay'])]
class EventOperation extends EventDetail
{
    protected $table = 'event_operations';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'power_consumption' => 'integer',
            'bus_power' => 'boolean',
            'house_delay' => 'boolean',
        ];
    }
}
