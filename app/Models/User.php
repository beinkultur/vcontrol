<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['first_name', 'last_name', 'email', 'password', 'account_type', 'employee_id', 'trade_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
        ];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function isSuper(): bool
    {
        return $this->roles->contains(fn (Role $role): bool => $role->is_super);
    }

    /**
     * Vorläufig, bis das Rechtesystem portiert ist: aktive Konten mit mindestens
     * einer internen Rolle. Die Rolle „extern“ bekommt ein eigenes Panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active
            && $this->roles->contains(fn (Role $role): bool => $role->slug !== Role::EXTERN);
    }

    public function getFilamentName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name) ?: $this->email;
    }
}
