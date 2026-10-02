<?php

namespace App\Models;

use App\Models\Concerns\StampsAuthor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Ein versendetes Daysheet: Link auf die Event-Infos für Externe, gültig ab
 * Versand bis zum Ende des Tages nach der Veranstaltung (Daysheets::validUntil)
 * und jederzeit sperrbar. Gespeichert wird nur der Hash des Schlüssels – den
 * Link selbst kennen nur die Empfänger der Mail.
 */
#[Fillable(['event_id', 'token_hash', 'recipients_to', 'recipients_bcc', 'subject', 'body', 'expires_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class Daysheet extends Model
{
    use StampsAuthor;

    /** Länge des Schlüssels im Link (Buchstaben und Ziffern, gut 280 Bit) */
    public const TOKEN_LENGTH = 48;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipients_to' => 'array',
            'recipients_bcc' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public static function newToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Daysheet zum Schlüssel aus dem Link – auch abgelaufene, die Seite sagt dann warum. */
    public static function findByToken(string $token): ?self
    {
        if (strlen($token) !== self::TOKEN_LENGTH || !ctype_alnum($token)) {
            return null;
        }

        return self::query()->where('token_hash', self::hashToken($token))->first();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isRevoked() => 'gesperrt',
            $this->isExpired() => 'abgelaufen',
            default => 'gültig',
        };
    }

    /** @return list<string> */
    public function allRecipients(): array
    {
        return array_values(array_unique([...($this->recipients_to ?? []), ...($this->recipients_bcc ?? [])]));
    }
}
