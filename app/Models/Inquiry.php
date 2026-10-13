<?php

namespace App\Models;

use App\Models\Concerns\StampsAuthor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Anfrage (Option) eines Veranstalters wie vc_inquiries der PHP-Version: ein
 * oder mehrere Wunschtermine (InquiryDate), eine Optionsfrist, Eckdaten fürs
 * spätere Event. Je Tag zählt die Reihenfolge des Eingangs (1. Option,
 * 2. Option …, App\Support\Inquiries). Bestätigt wird genau ein Termin – daraus
 * entsteht ein Event –, zurückgezogen bleibt die Anfrage im Archiv.
 */
#[Fillable(['promoter_id', 'title', 'option_deadline', 'comment', 'event_type1', 'event_type2', 'seating', 'areas', 'status', 'event_id', 'confirmed_date_id'])]
class Inquiry extends Model
{
    use StampsAuthor;

    public const OPEN = 'offen';

    public const CONFIRMED = 'bestätigt';

    public const WITHDRAWN = 'zurückgezogen';

    /** Vorgabe für die Optionsfrist neuer Anfragen, in Tagen */
    public const DEADLINE_DAYS = 28;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'option_deadline' => 'date',
            'seating' => 'array',
            'areas' => 'array',
        ];
    }

    /** @return BelongsTo<Promoter, $this> */
    public function promoter(): BelongsTo
    {
        return $this->belongsTo(Promoter::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<InquiryDate, $this> */
    public function dates(): HasMany
    {
        return $this->hasMany(InquiryDate::class)->orderBy('option_date')->orderBy('id');
    }

    /** @return BelongsTo<InquiryDate, $this> */
    public function confirmedDate(): BelongsTo
    {
        return $this->belongsTo(InquiryDate::class, 'confirmed_date_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            self::OPEN => 'Offen',
            self::CONFIRMED => 'Bestätigt',
            self::WITHDRAWN => 'Zurückgezogen',
            default => (string) $status,
        };
    }
}
