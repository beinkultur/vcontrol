<?php

namespace App\Models;

use App\Access\Access;
use App\Access\Area;
use App\Enums\AccountType;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['first_name', 'last_name', 'email', 'password', 'account_type', 'employee_id', 'trade_id', 'is_active', 'calendar_permissions'])]
#[Hidden(['password', 'remember_token'])]
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
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'account_type' => AccountType::class,
            'calendar_permissions' => 'array',
        ];
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
