<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Eintrag im Änderungsprotokoll (App\Support\Audit), unveränderlich.
 * IP-Adresse und Browser zeigt nur Admins die Detailansicht; versteckt, damit
 * sie nicht über attributesToArray() in den Livewire-Zustand anderer geraten.
 */
#[Fillable(['user_id', 'user_name', 'subject', 'subject_key', 'subject_label', 'event_id', 'action', 'old_values', 'new_values', 'ip_address', 'user_agent'])]
#[Hidden(['ip_address', 'user_agent'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
