<?php

namespace App\Models;

use App\Models\Concerns\StampsAuthor;
use App\Support\AdvancingCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Advancing eines Events: Kopie des Fragenkatalogs, Vorbelegung des Planers
 * (prefill) und was der Veranstalter abweichend davon eingetragen hat (answers),
 * beides Schlüssel → Wert. Angezeigt wird answers[key] ?? prefill[key]
 * (App\Support\Advancings).
 *
 * Der Link (/advancing/{Schlüssel}) gilt ab Versand bis zum Ende des Tages nach
 * der Veranstaltung und ist sperrbar. Gesucht wird über den Hash; der Schlüssel
 * liegt verschlüsselt daneben, damit der Planer den Link kopieren kann. Beides
 * bleibt aus Audit, JSON und Livewire-Zustand draußen.
 */
#[Fillable([
    'event_id', 'advancing_template_id', 'template_name', 'intro', 'structure', 'prefill', 'answers',
    'token_hash', 'token', 'expires_at', 'revoked_at', 'sent_at', 'recipients_to', 'recipients_bcc', 'sent_by',
    'first_opened_at', 'last_saved_at', 'submitted_at', 'submit_count', 'closed_at', 'closed_by_name',
])]
#[Hidden(['token_hash', 'token'])]
class EventAdvancing extends Model
{
    use StampsAuthor;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'structure' => 'array',
            'prefill' => 'array',
            'answers' => 'array',
            'token' => 'encrypted',
            'recipients_to' => 'array',
            'recipients_bcc' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'sent_at' => 'datetime',
            'first_opened_at' => 'datetime',
            'last_saved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'closed_at' => 'datetime',
            'submit_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public static function newToken(): string
    {
        return Daysheet::newToken();
    }

    public static function hashToken(string $token): string
    {
        return Daysheet::hashToken($token);
    }

    /** Advancing zum Schlüssel aus dem Link – auch gesperrte und abgelaufene, die Seite sagt dann warum. */
    public static function findByToken(string $token): ?self
    {
        if (strlen($token) !== Daysheet::TOKEN_LENGTH || !ctype_alnum($token)) {
            return null;
        }

        return self::query()->where('token_hash', self::hashToken($token))->first();
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null && $this->token_hash !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Ohne Ablaufdatum (nie versendet) gilt der Link als abgelaufen. */
    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function isLinkValid(): bool
    {
        return $this->isSent() && !$this->isRevoked() && !$this->isExpired();
    }

    public function link(): ?string
    {
        return filled($this->token) ? route('advancing.show', $this->token) : null;
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isClosed() => 'abgeschlossen',
            !$this->isSent() => 'Entwurf',
            $this->isRevoked() => 'gesperrt',
            $this->isExpired() => 'abgelaufen',
            $this->submitted_at !== null => 'abgegeben',
            $this->first_opened_at !== null => 'geöffnet',
            default => 'versendet',
        };
    }

    /** @return array<string, array<string, mixed>> Fragen nach Schlüssel, ohne Infotexte */
    public function questions(): array
    {
        return AdvancingCatalog::questions((array) $this->structure);
    }
}
