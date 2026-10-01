<?php

namespace App\Models;

use App\Enums\RoomUsage;
use App\Support\EventNumber;
use App\Support\IncomingInvoices;
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
    'closed',
])]
class Event extends Model
{
    public const STATUS_CANCELLED = 'storniert';

    /** FIBU-Status, der ein Event aus der normalen Buchhaltungsliste nimmt. */
    public const ACCOUNTING_FINAL = 'Endabrechnung gestellt';

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

    /** @return HasMany<EventGuest, $this> */
    public function guests(): HasMany
    {
        return $this->hasMany(EventGuest::class);
    }

    /** @return HasMany<EventNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(EventNote::class);
    }

    /** @return HasMany<EventShowChecklist, $this> */
    public function showChecklists(): HasMany
    {
        return $this->hasMany(EventShowChecklist::class);
    }

    /** @return HasMany<Damage, $this> */
    public function damages(): HasMany
    {
        return $this->hasMany(Damage::class);
    }

    /** @return HasMany<OrderSlip, $this> */
    public function orderSlips(): HasMany
    {
        return $this->hasMany(OrderSlip::class);
    }

    /** @return HasMany<HandoverProtocol, $this> */
    public function handoverProtocols(): HasMany
    {
        return $this->hasMany(HandoverProtocol::class);
    }

    /** Dateien direkt an diesem Event. @return HasMany<EventFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(EventFile::class);
    }

    /** Übergreifende Dateien, die an dieses Event gehängt sind. @return BelongsToMany<EventFile, $this> */
    public function linkedFiles(): BelongsToMany
    {
        return $this->belongsToMany(EventFile::class, 'event_file_links', 'event_id', 'file_id')
            ->where('event_files.is_shared', true);
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

    /** Backstage-Räume; setzt beim Zuordnen usage_type automatisch. */
    public function backstageRooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'event_room')->withPivotValue('usage_type', RoomUsage::Backstage->value);
    }

    /** Büros; setzt beim Zuordnen usage_type automatisch. */
    public function officeRooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'event_room')->withPivotValue('usage_type', RoomUsage::Office->value);
    }

    /**
     * Finanz-Warnung wie in der PHP-Version: Das Event ist höchstens 14 Tage
     * entfernt (oder vorbei), aber der Vertrag ist nicht zurück (bzw. kein
     * Rahmenvertrag) oder die 2. Rate ist nicht gezahlt.
     */
    public function hasFinanceAlert(): bool
    {
        if ($this->starts_at === null || today()->diffInDays($this->starts_at->copy()->startOfDay(), false) > 14) {
            return false;
        }

        // Wertgenau je Komma-Wert, wie containsTag() in der PHP-Version
        $contract = array_map(fn (string $v): string => mb_strtolower(trim($v)), explode(',', (string) $this->finance?->contract_status));
        $contractOk = array_intersect($contract, ['vertrag zurück', 'rahmenvertrag']) !== [];
        $secondRatePaid = in_array('2. Rate gezahlt', $this->finance?->accounting_status ?? [], true);

        return !($contractOk && $secondRatePaid);
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

    /** FIBU-Status enthält „Endabrechnung gestellt“. */
    public function scopeFinalInvoiced(Builder $query, bool $final = true): void
    {
        $method = $final ? 'whereHas' : 'whereDoesntHave';
        $query->{$method}('finance', fn (Builder $f) => $f->whereJsonContains('accounting_status', self::ACCOUNTING_FINAL));
    }

    /** Schalter „Abrechnung abgeschlossen“. */
    public function scopeAccountingClosed(Builder $query, bool $closed = true): void
    {
        $method = $closed ? 'whereHas' : 'whereDoesntHave';
        $query->{$method}('finance', fn (Builder $f) => $f->where('accounting_closed', true));
    }

    /**
     * Alle aktiven Eingangsrechnungen sind da. Gleiche Regel wie
     * App\Support\IncomingInvoices: gespeicherter Eintrag gewinnt, ohne Eintrag
     * sind Mobiliar (bei „bestuhlt“) und Haus-Delay automatisch aktiv.
     */
    public function scopeIncomingInvoicesSettled(Builder $query): void
    {
        $stored = fn (string $key) => fn (Builder $i) => $i->where('invoice_key', $key);

        $query
            ->whereDoesntHave('incomingInvoices', fn (Builder $i) => $i->where('is_active', true)->where('is_received', false))
            ->where(fn (Builder $q) => $q
                ->whereNull('seating')
                ->orWhereJsonDoesntContain('seating', IncomingInvoices::SEATING_TRIGGER)
                ->orWhereHas('incomingInvoices', $stored('mobiliar_stuehle')))
            ->where(fn (Builder $q) => $q
                ->whereDoesntHave('operation', fn (Builder $o) => $o->where('house_delay', true))
                ->orWhereHas('incomingInvoices', $stored('cobra_hausdelay')));
    }

    /** Archivreif: abgeschlossen und alle aktiven Eingangsrechnungen da. */
    public function scopeArchiveReady(Builder $query, bool $ready = true): void
    {
        $condition = fn (Builder $q) => $q->accountingClosed()->incomingInvoicesSettled();
        $ready ? $query->where($condition) : $query->whereNot($condition);
    }
}
