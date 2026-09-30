<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/** Notiz an einem Event: Betreff und Text, mit Verfasser und letztem Bearbeiter. */
#[Fillable(['event_id', 'subject', 'body'])]
class EventNote extends Model
{
    protected static function booted(): void
    {
        static::creating(function (EventNote $note): void {
            $user = Auth::user();
            if ($user instanceof User) {
                $note->created_by ??= $user->id;
                $note->created_by_name ??= $user->getFilamentName();
            }
        });

        static::updating(function (EventNote $note): void {
            $user = Auth::user();
            if ($user instanceof User && $note->isDirty(['subject', 'body'])) {
                $note->updated_by = $user->id;
                $note->updated_by_name = $user->getFilamentName();
            }
        });
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Einzeilige Vorschau wie in der PHP-Version. */
    public function excerpt(int $length = 140): string
    {
        $oneLine = trim((string) preg_replace('/\s+/u', ' ', $this->body));

        return mb_strlen($oneLine) <= $length ? $oneLine : mb_substr($oneLine, 0, $length - 1) . '…';
    }
}
