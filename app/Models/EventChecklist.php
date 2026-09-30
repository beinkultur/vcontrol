<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/** Checkliste eines Events: Punkte mit ja (yes) / nein (no) / entfällt (na). */
#[Fillable(['hands', 'traffic', 'pvc_setup', 'pvc_teardown', 'cleaning', 'interim_cleaning', 'bar_setup', 'bar_teardown', 'chairs_ordered', 'merch_fee', 'merch_fee_check', 'special_cleaning', 'power_ant', 'house_rig_early', 'briefing_complete'])]
class EventChecklist extends EventDetail
{
    protected $table = 'event_checklists';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'power_ant' => 'boolean',
            'house_rig_early' => 'boolean',
            'briefing_complete' => 'boolean',
        ];
    }
}
