<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/** Bühnendaten, Grundlage des Bühnenplans. */
#[Fillable(['stage_info', 'width', 'depth', 'height', 'wing_sl_width', 'wing_sl_depth', 'wing_sr_width', 'wing_sr_depth', 'wing_sl_offset', 'wing_sr_offset', 'extra_platforms', 'rollpodest_width', 'rollpodest_depth', 'podest_total', 'stair_third', 'stair_sl_offset', 'stair_sr_offset', 'backwall_cm', 'other_info', 'stage_notes', 'notes', 'sold_out_award'])]
class EventStage extends EventDetail
{
    protected $table = 'event_stages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'decimal:2',
            'depth' => 'decimal:2',
            'height' => 'decimal:2',
            'sold_out_award' => 'boolean',
        ];
    }
}
