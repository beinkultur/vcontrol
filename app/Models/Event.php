<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'va_nr', 'va_id', 'title', 'promoter_id', 'status', 'event_type1', 'event_type2',
    'starts_at', 'ends_at', 'pax_expected', 'pax', 'areas', 'seating', 'ticketing',
    'wlan', 'wlan_password', 'description', 'booking_notes', 'onsite_contact',
    'doing_closed', 'closed',
])]
class Event extends Model
{
    public const STATUS_CANCELLED = 'storniert';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'pax_expected' => 'integer',
            'pax' => 'integer',
            'areas' => 'array',
            'seating' => 'array',
            'doing_closed' => 'boolean',
            'closed' => 'boolean',
        ];
    }

    /** @return BelongsTo<Promoter, $this> */
    public function promoter(): BelongsTo
    {
        return $this->belongsTo(Promoter::class);
    }

    /** Liegt in der Vergangenheit, ist aber noch nicht abgeschlossen. */
    public function isOverdue(): bool
    {
        return !$this->closed && $this->starts_at !== null && $this->starts_at->lt(today());
    }

    /** @param Builder<self> $query */
    public function scopeUpcoming(Builder $query): void
    {
        $query->whereDate('starts_at', '>=', today());
    }

    /** @param Builder<self> $query */
    public function scopePast(Builder $query): void
    {
        $query->whereDate('starts_at', '<', today());
    }
}
