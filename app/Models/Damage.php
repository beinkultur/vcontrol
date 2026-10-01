<?php

namespace App\Models;

use App\Models\Concerns\StampsAuthor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schaden wie in der PHP-Version: an einem Event oder allgemein, mit Fotos,
 * „behoben“. Fotos liegen nicht öffentlich (Disk „local“) und werden über
 * DamagePhotoController mit Rechteprüfung ausgeliefert.
 */
#[Fillable(['event_id', 'recorded_at', 'description', 'is_fixed', 'photos', 'photo_names', 'recorded_by_name'])]
class Damage extends Model
{
    use StampsAuthor;

    public const DISK = 'local';

    /** Fotos liegen nur hier (DamageFields) – die Fotoroute liefert nichts anderes aus. */
    public const PHOTO_DIRECTORY = 'damages';

    public const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'is_fixed' => 'boolean',
            'photos' => 'array',
            'photo_names' => 'array',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** „Aufgenommen durch“ wie in der PHP-Version: Verfasser, sonst der Name aus dem Import. */
    public function recorderName(): ?string
    {
        return $this->created_by_name ?: $this->recorded_by_name;
    }

    /** @return list<string> */
    public function photoUrls(): array
    {
        return array_values(array_map(
            fn (int $index): string => route('damages.photo', ['damage' => $this, 'index' => $index]),
            array_keys(array_values($this->photos ?? [])),
        ));
    }
}
