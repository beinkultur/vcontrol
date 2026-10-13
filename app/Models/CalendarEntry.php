<?php

namespace App\Models;

use App\Models\Concerns\StampsAuthor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eigener Eintrag auf einer Kalender-Ebene wie Wartung oder Technik
 * (vc_calendar_entries): ganztägig oder mit Uhrzeit, mit Beschreibung. Auf den
 * Ebenen Events, Anfragen und Persönlich gibt es keine eigenen Einträge – deren
 * Inhalt kommt aus Events, Anfragen und dem Personal (App\Support\CalendarItems).
 */
#[Fillable(['calendar_key', 'title', 'description', 'starts_at', 'ends_at', 'all_day'])]
class CalendarEntry extends Model
{
    use StampsAuthor;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
        ];
    }

    /** @return BelongsTo<Calendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'calendar_key', 'key');
    }
}
