<?php

namespace App\Models;

use App\Support\EventNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'va_nr', 'va_id', 'title', 'promoter_id', 'status', 'event_type1', 'event_type2',
    'starts_at', 'ends_at', 'pax_expected', 'pax', 'areas', 'seating', 'ticketing',
    'wlan', 'wlan_password', 'description', 'booking_notes', 'onsite_contact',
    'doing_closed', 'closed',
])]
class Event extends Model
{
    public const STATUS_CANCELLED = 'storniert';

    protected static function booted(): void
    {
        // Neue Events bekommen eine laufende Nummer und daraus die VA-ID,
        // sofern nicht ausdrücklich eine mitgegeben wird
        static::creating(function (Event $event): void {
            if (blank($event->va_nr)) {
                $event->va_nr = EventNumber::next();
            }
            if (blank($event->va_id)) {
                $event->va_id = EventNumber::buildVaId(EventNumber::customerNoOf($event->promoter_id), $event->va_nr);
            }
        });

        // Anderer Veranstalter: VA-ID neu bilden, laufende Nummer behalten
        static::updating(function (Event $event): void {
            if ($event->isDirty('promoter_id') && !$event->isDirty('va_id') && filled($event->va_nr)) {
                $event->va_id = EventNumber::buildVaId(EventNumber::customerNoOf($event->promoter_id), $event->va_nr);
            }
        });
    }

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

    /** @return HasOne<EventFinance, $this> */
    public function finance(): HasOne
    {
        return $this->hasOne(EventFinance::class);
    }

    /** @return HasOne<EventSchedule, $this> */
    public function schedule(): HasOne
    {
        return $this->hasOne(EventSchedule::class);
    }

    /** @return HasOne<EventPr, $this> */
    public function pr(): HasOne
    {
        return $this->hasOne(EventPr::class);
    }

    /** @return HasOne<EventOperation, $this> */
    public function operation(): HasOne
    {
        return $this->hasOne(EventOperation::class);
    }

    /** @return HasOne<EventStage, $this> */
    public function stage(): HasOne
    {
        return $this->hasOne(EventStage::class);
    }

    /** @return HasOne<EventChecklist, $this> */
    public function checklist(): HasOne
    {
        return $this->hasOne(EventChecklist::class);
    }

    /** @return HasMany<EventService, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(EventService::class);
    }

    /** @return HasMany<EventAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(EventAssignment::class);
    }

    /** @return HasMany<EventIncomingInvoice, $this> */
    public function incomingInvoices(): HasMany
    {
        return $this->hasMany(EventIncomingInvoice::class);
    }

    /** @return BelongsToMany<Room, $this> */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'event_room')->withPivot('usage_type');
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
