<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\AccountType;
use App\Filament\Support\AccessFields;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Person')
                    ->columns(2)
                    ->schema([
                        TextInput::make('first_name')
                            ->label('Vorname')
                            ->maxLength(120),
                        TextInput::make('last_name')
                            ->label('Nachname')
                            ->required()
                            ->maxLength(120),
                        TextInput::make('email')
                            ->label('E-Mail')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label('Passwort')
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? 'Leer lassen, um das Passwort nicht zu ändern.'
                                : null),
                    ]),
                Section::make('Zugang')
                    ->columns(2)
                    ->schema([
                        CheckboxList::make('roles')
                            ->label('Rollen')
                            ->relationship(
                                name: 'roles',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->orderBy('sort_order'),
                            )
                            ->required()
                            ->columns(3)
                            ->columnSpanFull()
                            ->disableOptionWhen(fn (int|string $value): bool => !self::actorIsSuper() && self::isSuperRole($value))
                            ->helperText('Mehrere Rollen addieren sich. Die Admin-Rolle vergeben nur Admins.'),
                        Select::make('account_type')
                            ->label('Kontotyp')
                            ->options(AccountType::class)
                            ->default(AccountType::VenueEmployee)
                            ->required()
                            ->native(false),
                        Toggle::make('is_active')
                            ->label('Aktiv')
                            ->helperText('Inaktive Konten können sich nicht anmelden.')
                            ->default(true)
                            ->inline(false),
                    ]),
                Section::make('Kalender')
                    ->description('Zusätzlich zu den Rechten aus den Rollen – es gilt jeweils die höhere Stufe.')
                    ->columns(2)
                    ->collapsed()
                    ->schema(fn (): array => AccessFields::calendars('calendar_permissions')),
            ]);
    }

    private static function actorIsSuper(): bool
    {
        $actor = Auth::user();

        return $actor instanceof User && $actor->access()->isSuper();
    }

    private static function isSuperRole(int|string $roleId): bool
    {
        return Role::query()->whereKey($roleId)->where('is_super', true)->exists();
    }
}
