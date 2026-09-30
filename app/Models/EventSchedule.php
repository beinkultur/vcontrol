<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/** Zeiten am Veranstaltungstag (Get-in bis Load-out). */
#[Fillable(['get_in', 'load_in', 'admission', 'vip_admission', 'start_time', 'end_time', 'curfew', 'load_out'])]
class EventSchedule extends EventDetail
{
    protected $table = 'event_schedules';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Uhrzeiten als Text HH:MM:SS, ohne Datum
        ];
    }
}
