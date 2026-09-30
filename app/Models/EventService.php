<?php

namespace App\Models;

use App\Enums\Responsible;
use App\Enums\ServiceCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eine Leistung am Event: wer sie stellt, welches Gewerk, Notiz. */
#[Fillable(['event_id', 'service', 'trade_id', 'provider_label', 'responsible', 'is_active', 'note'])]
class EventService extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service' => ServiceCode::class,
            'responsible' => Responsible::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Trade, $this> */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function providerName(): ?string
    {
        return $this->trade?->displayName() ?? $this->provider_label;
    }
}
