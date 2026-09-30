<?php

namespace App\Models;

use App\Support\StagePodests;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/** Bühnendaten, Grundlage des Bühnenplans. */
#[Fillable(['stage_info', 'width', 'depth', 'height', 'wing_sl_width', 'wing_sl_depth', 'wing_sr_width', 'wing_sr_depth', 'wing_sl_offset', 'wing_sr_offset', 'extra_platforms', 'rollpodest_width', 'rollpodest_depth', 'podest_total', 'stair_third', 'stair_sl_offset', 'stair_sr_offset', 'backwall_cm', 'other_info', 'stage_notes', 'notes', 'sold_out_award'])]
class EventStage extends EventDetail
{
    protected $table = 'event_stages';

    /** Vorgaben der PHP-Version für neue Bühnen (Bestand: 238 von 239 so). */
    protected $attributes = [
        'wing_sl_offset' => 1,
        'wing_sr_offset' => 1,
        'backwall_cm' => 160,
    ];

    /** Maße, aus denen sich die Zahl der Podeste ergibt. */
    public const PODEST_FIELDS = ['width', 'depth', 'wing_sl_width', 'wing_sl_depth', 'wing_sr_width', 'wing_sr_depth', 'rollpodest_width', 'rollpodest_depth', 'extra_platforms'];

    protected static function booted(): void
    {
        // Wie in der PHP-Version wird die Summe beim Speichern der Maße neu
        // berechnet – aber nur dann: Der Workspace speichert alle Abschnitte
        // zusammen, und bei Altdaten soll die Summe aus AppSheet stehen bleiben.
        static::saving(function (EventStage $stage): void {
            if ($stage->exists && !$stage->isDirty(self::PODEST_FIELDS)) {
                return;
            }
            $total = StagePodests::calculate($stage->getAttributes())['total'];
            $stage->podest_total = $total > 0 ? $total : null;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'decimal:2',
            'depth' => 'decimal:2',
            'height' => 'decimal:2',
            'wing_sl_width' => 'decimal:2',
            'wing_sl_depth' => 'decimal:2',
            'wing_sr_width' => 'decimal:2',
            'wing_sr_depth' => 'decimal:2',
            'sold_out_award' => 'boolean',
        ];
    }
}
