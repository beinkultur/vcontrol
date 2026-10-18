<?php

namespace App\Models;

use App\Access\Access;
use App\Access\Area;
use App\Enums\AccountType;
use App\Support\Audit;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['first_name', 'last_name', 'initials', 'email', 'phone', 'password', 'must_change_password', 'account_type', 'positions', 'trade_id', 'is_active', 'calendar_permissions', 'calendar_view'])]
#[Hidden(['password', 'remember_token', 'calendar_token', 'calendar_token_hash'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    private ?Access $access = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Passwort vom Admin vergeben: beim nächsten Anmelden ein eigenes festlegen (RequirePasswordChange)
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'account_type' => AccountType::class,
            'positions' => 'array',
            'calendar_permissions' => 'array',
            'calendar_token' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Trade, $this> */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    /**
     * Positionen (VL, VfV …) für Dienste im Personal der Events – nur bei
     * Personen-Konten (AccountType::hasPositions), sonst leer.
     *
     * @return list<string>
     */
    public function positionList(): array
    {
        return ($this->account_type?->hasPositions() ?? false) ? array_values((array) ($this->positions ?? [])) : [];
    }

    /** Verknüpftes Gewerk, sofern der Kontotyp es vorsieht (Gewerk-Account) */
    public function linkedTradeId(): ?int
    {
        return ($this->account_type?->linksTrade() ?? false) && $this->trade_id !== null ? (int) $this->trade_id : null;
    }

    /**
     * Schlüssel des persönlichen ICS-Feeds (/kalender/persoenlich.ics). Beim
     * ersten Abruf angelegt, ohne Eintrag im Änderungsprotokoll.
     */
    public function personalFeedToken(): string
    {
        $token = (string) $this->calendar_token;

        return $token !== '' ? $token : Audit::quietly(fn (): string => $this->renewPersonalFeedToken());
    }

    /** Neuer Schlüssel – die bisherige Adresse gilt danach nicht mehr. */
    public function renewPersonalFeedToken(): string
    {
        $token = Str::random(40);
        $this->forceFill(['calendar_token' => $token, 'calendar_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    /** Konto zu einem Feed-Schlüssel; gesucht wird über den Hash, der Schlüssel selbst liegt verschlüsselt */
    public static function forFeedToken(mixed $token): ?self
    {
        if (!is_string($token) || strlen($token) < 20) {
            return null;
        }

        return self::query()->where('calendar_token_hash', hash('sha256', $token))->first();
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->using(RoleUser::class);
    }

    public function access(): Access
    {
        return $this->access ??= new Access($this);
    }

    /** Einziger aktiver Benutzer mit Vollzugriff (Admin oder System-Admin). */
    public function isLastSuper(): bool
    {
        if (!$this->access()->isSuper()) {
            return false;
        }

        return self::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $query) => $query->where(fn (Builder $roles) => $roles->where('is_super', true)->orWhere('is_system_admin', true)))
            ->count() <= 1;
    }

    /** Einziger aktiver System-Admin. */
    public function isLastSystemAdmin(): bool
    {
        if (!$this->access()->isSystemAdmin()) {
            return false;
        }

        return self::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $query) => $query->where('is_system_admin', true))
            ->count() <= 1;
    }

    /**
     * System-Admins sieht nur, wer selbst einer ist – in der Benutzerliste, in
     * Filtern und Auswahllisten. Was sie geändert haben, steht weiter im Audit.
     */
    public function scopeVisibleTo(Builder $query, ?self $viewer): void
    {
        if (!($viewer?->access()->isSystemAdmin() ?? false)) {
            $query->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('is_system_admin', true));
        }
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Externe kommen ins selbe Panel; dort sehen sie nur „Meine Events“ und
        // ihren Kalender, alles andere sperren die Policies
        return $this->is_active && match ($panel->getId()) {
            'app' => $this->access()->canUseApp() || $this->access()->canUseExtern() || $this->access()->can(Area::KalenderExtern),
            default => false,
        };
    }

    public function getFilamentName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name) ?: $this->email;
    }
}
