<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\AccountType;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Support\AccessFields;
use App\Models\Role;
use App\Models\Trade;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                        TextInput::make('initials')
                            ->label('Kürzel')
                            ->helperText('Für die Projektleitung in der Event-Liste.')
                            ->maxLength(20),
                        TextInput::make('phone')
                            ->label('Telefon')
                            ->helperText('Steht im Daysheet, wenn die Person im Personal eines Events eingetragen ist.')
                            ->tel()
                            ->maxLength(50),
                    ]),
                Section::make('Zugang')
                    ->columns(2)
                    ->schema([
                        CheckboxList::make('roles')
                            ->label('Rollen')
                            ->relationship(
                                name: 'roles',
                                titleAttribute: 'name',
                                // Die System-Admin-Rolle nur für System-Admins (AccountSafety prüft es beim Speichern)
                                modifyQueryUsing: fn (Builder $query): Builder => $query->visibleTo(self::actor())->orderBy('sort_order'),
                            )
                            ->required()
                            ->columns(3)
                            ->columnSpanFull()
                            ->disableOptionWhen(fn (int|string $value): bool => !self::actorIsSuper() && self::isSuperRole($value))
                            ->helperText('Mehrere Rollen addieren sich. Die Admin-Rolle vergeben nur Admins. Was eine Rolle darf, steht unter Rollen & Rechte.'),
                        Select::make('account_type')
                            ->label('Kontotyp')
                            ->options(AccountType::class)
                            ->default(AccountType::VenueEmployee)
                            ->required()
                            ->native(false)
                            ->live(),
                        Toggle::make('is_active')
                            ->label('Aktiv')
                            ->helperText('Inaktive Konten können sich nicht anmelden.')
                            ->default(true)
                            ->inline(false),
                        CheckboxList::make('positions')
                            ->label('Positionen')
                            ->helperText('Für welche Dienste die Person im Personal der Events zur Wahl steht: House Rep. braucht VL, VfV die Position VfV. Werte pflegen unter Stammdaten › Feldoptionen › Position.')
                            ->options(fn (?User $record): array => ContactResource::positionOptions($record?->positions))
                            ->columns(3)
                            ->visible(fn (Get $get): bool => self::type($get)?->hasPositions() ?? false)
                            ->columnSpanFull(),
                        Select::make('trade_id')
                            ->label('Gewerk')
                            ->helperText('Das Konto sieht alles, woran dieses Gewerk beteiligt ist (Personal und Leistungen der Events) – unter „Meine Events“ und im Kalender. Mehrere Konten je Gewerk sind möglich.')
                            ->options(fn (?User $record): array => self::tradeOptions($record))
                            ->searchable()
                            ->visible(fn (Get $get): bool => self::type($get)?->linksTrade() ?? false),
                    ]),
                Section::make('Kalender')
                    ->description('Zusätzlich zu den Rechten aus den Rollen – es gilt jeweils die höhere Stufe.')
                    ->columns(2)
                    ->collapsed()
                    ->schema(fn (): array => AccessFields::calendars('calendar_permissions')),
            ]);
    }

    /**
     * Passend zum Kontotyp: Personen-Konten tragen Positionen, Gewerk-Accounts
     * ein Gewerk; das jeweils andere wird geleert (ausgeblendete Felder speichert
     * Filament nicht mit).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withLinks(array $data): array
    {
        $type = $data['account_type'] ?? null;
        $type = $type instanceof AccountType ? $type : AccountType::tryFrom((string) $type);
        $positions = array_values(array_filter(array_map(fn (mixed $value): string => trim((string) $value), (array) ($data['positions'] ?? []))));
        $data['positions'] = ($type?->hasPositions() ?? false) && $positions !== [] ? $positions : null;
        $data['trade_id'] = ($type?->linksTrade() ?? false) && filled($data['trade_id'] ?? null) ? (int) $data['trade_id'] : null;

        return $data;
    }

    private static function type(Get $get): ?AccountType
    {
        $type = $get('account_type');

        return $type instanceof AccountType ? $type : AccountType::tryFrom((string) $type);
    }

    /** @return array<int, string> nicht archivierte Gewerke, das aktuelle bleibt wählbar */
    private static function tradeOptions(?User $record): array
    {
        return Trade::query()
            ->where(fn (Builder $query): Builder => $query->where('is_archived', false)->orWhere('id', $record?->trade_id))
            ->orderBy('name')->get()
            ->mapWithKeys(fn (Trade $trade): array => [$trade->id => trim(($trade->short_name ? $trade->short_name . ' — ' : '') . $trade->name)])
            ->all();
    }

    private static function actor(): ?User
    {
        $actor = Auth::user();

        return $actor instanceof User ? $actor : null;
    }

    private static function actorIsSuper(): bool
    {
        return self::actor()?->access()->isSuper() ?? false;
    }

    private static function isSuperRole(int|string $roleId): bool
    {
        return Role::query()->whereKey($roleId)->where(fn (Builder $query) => $query->where('is_super', true)->orWhere('is_system_admin', true))->exists();
    }
}
