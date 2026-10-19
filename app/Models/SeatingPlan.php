<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Standard-Saalplan der Venue: ein PDF mit Namen, den Bereichen, die der Plan
 * nutzt, und seiner Bestuhlung (Werte der Feldoptionen „Bereiche“ und
 * „Bestuhlung“). Ein Event wählt einen davon oder lädt einen eigenen hoch
 * (Event::seatingPlan…); Bereiche und Bestuhlung übernimmt es beim Auswählen
 * und passt sie bei Bedarf an. Die Datei liegt nicht öffentlich
 * (Disk wie EventFile), ausgeliefert wird über SeatingPlanController.
 * Mit Kapazität (seit 19.10.2026) warnt ein Event, dessen PAX erwartet darüber
 * liegt (capacityWarning).
 */
#[Fillable(['name', 'capacity', 'path', 'original_name', 'areas', 'seating', 'is_active'])]
class SeatingPlan extends Model
{
    public const DISK = EventFile::DISK;

    public const DIRECTORY = 'seating-plans';

    protected static function booted(): void
    {
        // Typ und Größe am gespeicherten Inhalt, nicht an der Angabe des Browsers
        static::saving(function (SeatingPlan $plan): void {
            if ($plan->isDirty('path') && filled($plan->path)) {
                $disk = Storage::disk(self::DISK);
                $plan->mime_type = $disk->mimeType($plan->path) ?: null;
                $plan->size = $disk->size($plan->path);
            }
        });

        // Neue Datei: die alte verschwindet, mit dem Plan auch seine Datei
        static::updated(function (SeatingPlan $plan): void {
            $old = $plan->getOriginal('path');
            if ($plan->wasChanged('path') && filled($old)) {
                Storage::disk(self::DISK)->delete($old);
            }
        });
        static::deleted(function (SeatingPlan $plan): void {
            Storage::disk(self::DISK)->delete($plan->path);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'areas' => 'array',
            'seating' => 'array',
            'is_active' => 'boolean',
            'size' => 'integer',
            'capacity' => 'integer',
        ];
    }

    /**
     * „PAX erwartet 4.500 liegt über der Kapazität des Saalplans „Vollbestuhlung“
     * (4.200).“ – oder null, wenn der Plan keine Kapazität hat oder sie reicht.
     */
    public static function capacityWarning(?self $plan, mixed $pax): ?string
    {
        if ($plan === null || $plan->capacity === null || !is_numeric($pax) || (int) $pax <= $plan->capacity) {
            return null;
        }

        return 'PAX erwartet ' . number_format((int) $pax, 0, ',', '.') . ' liegt über der Kapazität des Saalplans „'
            . $plan->name . '“ (' . number_format($plan->capacity, 0, ',', '.') . ').';
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function url(): string
    {
        return route('seating-plans.pdf', $this);
    }

    /** Nur ein am Inhalt als PDF erkanntes Dokument zeigt der Browser an, alles andere wird heruntergeladen. */
    public function isPdf(): bool
    {
        return self::detectedPdf($this->mime_type);
    }

    public static function detectedPdf(?string $mimeType): bool
    {
        return strtolower(trim(explode(';', (string) $mimeType)[0])) === 'application/pdf';
    }
}
