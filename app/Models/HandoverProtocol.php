<?php

namespace App\Models;

use App\Models\Concerns\StampsAuthor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Übergabeprotokoll wie in der PHP-Version: Inventar geht an einen Empfänger
 * (mit dessen Unterschrift) und wird später als zurückerhalten markiert.
 */
#[Fillable(['event_id', 'handed_to', 'handed_at', 'signature', 'status', 'returned_at', 'returned_by', 'comment'])]
class HandoverProtocol extends Model
{
    use StampsAuthor;

    public const OPEN = 'open';

    public const RETURNED = 'returned';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'handed_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<HandoverProtocolItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(HandoverProtocolItem::class, 'protocol_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function statusLabel(): string
    {
        return $this->isOpen() ? 'offen' : 'zurück';
    }
}
