<?php

namespace App\Models;

use App\Models\Concerns\StampsAuthor;
use App\Support\ShowChecklist;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Durchführungs-Checkliste eines Events (Prüfpunkte siehe App\Support\ShowChecklist). */
#[Fillable(['event_id', 'checked_at', 'house_rep', 'house_rep_signature', 'promoter_rep', 'promoter_rep_signature', 'checks', 'remarks'])]
class EventShowChecklist extends Model
{
    use StampsAuthor;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'checks' => 'array',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return array{done: int, missing: int, total: int} */
    public function counts(): array
    {
        return ShowChecklist::counts($this->checks);
    }
}
