<?php

namespace App\Models;

use App\Enums\AssignmentRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Rolle am Event, vergeben an einen Benutzer, einen Kontakt (ohne Anmeldung) oder ein Gewerk. */
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
            $assignee instanceof Contact => (string) $assignee->name,
            $assignee instanceof Trade => $assignee->displayName(),
            $assignee instanceof User => $assignee->getFilamentName(),
            default => '–',
        };
    }

    /**
     * Telefon und E-Mail der eingetragenen Person, des Kontakts oder Gewerks –
     * fürs Daysheet und den Extern-Bereich.
     *
     * @return array{phone: ?string, email: ?string}
     */
    public function assigneeContact(): array
    {
        $assignee = $this->assignee;
        if (!$assignee instanceof Contact && !$assignee instanceof User && !$assignee instanceof Trade) {
            return ['phone' => null, 'email' => null];
        }

        return [
            'phone' => filled($assignee->getAttribute('phone')) ? trim((string) $assignee->getAttribute('phone')) : null,
            'email' => filled($assignee->getAttribute('email')) ? trim((string) $assignee->getAttribute('email')) : null,
        ];
    }
}
