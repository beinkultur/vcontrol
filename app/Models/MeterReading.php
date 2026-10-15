<?php

namespace App\Models;

use App\Models\Concerns\StampsAuthor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein abgelesener Stand: je Event (position start/end, gepflegt im Event unter
 * Durchführung › Betrieb, Zeitpunkt aus den Event-Tagen) oder frei (ohne Event,
 * Seite Zählerstände). Der Verbrauch ergibt sich aus der Differenz
 * aufeinanderfolgender Stände (App\Support\MeterStats); mit $restart beginnt
 * der Stand neu (Zählerwechsel), seit dem vorigen zählt dann nichts.
 */
#[Fillable(['meter_id', 'event_id', 'position', 'read_at', 'value', 'restart', 'note'])]
class MeterReading extends Model
{
    use StampsAuthor;

    public const START = 'start';

    public const END = 'end';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'value' => 'decimal:3',
            'restart' => 'boolean',
        ];
    }

    /** @return BelongsTo<Meter, $this> */
    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function isFromEvent(): bool
    {
        return $this->event_id !== null;
    }

    /** „Event Anfang“, „Event Ende“, „Zählerwechsel“ oder „Ablesung“ */
    public function sourceLabel(): string
    {
        return match (true) {
            $this->position === self::START => 'Event Anfang',
            $this->position === self::END => 'Event Ende',
            $this->restart => 'Zählerwechsel',
            default => 'Ablesung',
        };
    }

    /** Fürs Änderungsprotokoll: „Strom · 12.10.2026“ */
    public function auditLabel(): string
    {
        $meter = Meter::query()->whereKey($this->meter_id)->value('name') ?? 'Zähler';

        return $meter . ($this->read_at !== null ? ' · ' . $this->read_at->format('d.m.Y') : '');
    }
}
