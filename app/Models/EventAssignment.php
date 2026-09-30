<?php

namespace App\Models;

use App\Enums\AssignmentRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Rolle am Event, vergeben an einen Mitarbeiter, ein Gewerk oder einen Benutzer. */
#[Fillable(['event_id', 'role', 'assignee_type', 'assignee_id', 'starts_at', 'ends_at'])]
class EventAssignment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AssignmentRole::class,
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return MorphTo<Model, $this> */
    public function assignee(): MorphTo
    {
        return $this->morphTo();
    }

    public function assigneeName(): string
    {
        $assignee = $this->assignee;

        return match (true) {
            $assignee instanceof Employee => $assignee->fullName(),
            $assignee instanceof Trade => $assignee->displayName(),
            $assignee instanceof User => $assignee->getFilamentName(),
            default => '–',
        };
    }
}
