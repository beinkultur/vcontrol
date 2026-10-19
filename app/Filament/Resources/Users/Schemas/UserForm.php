<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\AccountType;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Support\AccessFields;
use App\Models\Role;
use App\Models\Trade;
use App\Models\User;
use App\Support\AccessData;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class UserForm
{
    /** Schalter „Zugangsdaten per E-Mail senden“ – nicht gespeichert, ausgewertet in CreateUser/EditUser */
    public const SEND_ACCESS_DATA = 'send_access_data';

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
                        TextInput::make('initials')
                            ->label('Kürzel')
                            ->helperText('Für die Projektleitung in der Event-Liste.')
                            ->maxLength(20),
                        TextInput::make('phone')
                            ->label('Telefon')
                            ->helperText('Steht im Daysheet, wenn die Person bei einem Event unter Planung › Kontakte eingetragen ist.')
                            ->tel()
                            ->maxLength(50),
                    ]),
                // Seit 18.10.2026: Passwort generieren lassen und die Zugangsdaten per Mail schicken (App\Support\AccessData)
                Section::make('Anmeldung')
                    ->columns(2)
                    ->schema([
                        TextInput::make('email')
                            ->label('E-Mail')
                            ->helperText('Damit meldet sich die Person an.')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label('Passwort')
                            ->password()
                            ->revealable()
                            ->copyable(copyMessage: 'Passwort kopiert')
                            ->suffixAction(
                                Action::make('generatePassword')
                                    ->label('Passwort generieren')
                                    ->tooltip('Passwort generieren')
                                    ->icon(Heroicon::OutlinedSparkles)
                                    ->action(function (Set $set): void {
                                        $set('password', AccessData::password());
                                        $set(self::SEND_ACCESS_DATA, true);
                                    }),
                            )
                            ->minLength(8)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->live(onBlur: true)
                            ->helperText(fn (string $operation, ?User $record): string => self::passwordHelp($operation, $record)),
                        Toggle::make(self::SEND_ACCESS_DATA)
                            ->label(fn (string $operation): string => $operation === 'edit' ? 'Neues Passwort per E-Mail senden' : 'Zugangsdaten per E-Mail senden')
                            ->helperText('Nach dem Speichern an die E-Mail-Adresse oben: Adresse der Anmeldung, E-Mail-Adresse und Passwort. Nur für aktive Konten.')
                            ->dehydrated(false)
                            ->visible(fn (Get $get, string $operation): bool => (bool) $get('is_active') && ($operation === 'create' || filled($get('password'))))
                            ->columnSpanFull(),
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
                            ->inline(false)
                            ->live(),
                        CheckboxList::make('positions')
                            ->label('Positionen')
                            ->helperText('Für welche Rollen die Person bei den Events (Planung › Kontakte) zur Wahl steht: House Rep. braucht VL, VfV die Position VfV. Werte pflegen unter Stammdaten › Feldoptionen › Position.')
                            ->options(fn (?User $record): array => ContactResource::positionOptions($record?->positions))
                            ->columns(3)
                            ->visible(fn (Get $get): bool => self::type($get)?->hasPositions() ?? false)
                            ->columnSpanFull(),
                        Select::make('trade_id')
                            ->label('Gewerk')
                            ->helperText('Das Konto sieht alles, woran dieses Gewerk beteiligt ist (Rollen und Leistungen der Events) – unter „Meine Events“ und im Kalender. Mehrere Konten je Gewerk sind möglich.')
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

    /** Hinweis am Passwort: Ein Passwort vom Admin muss die Person beim nächsten Anmelden ersetzen (ChangePassword). */
    private static function passwordHelp(string $operation, ?User $record): string
    {
        if ($operation !== 'edit') {
            return 'Mindestens 8 Zeichen – oder generieren lassen. Beim ersten Anmelden legt die Person ein eigenes fest.';
        }
        if ($record !== null && $record->is(self::actor())) {
            return 'Leer lassen, um das Passwort nicht zu ändern.';
        }

        return ($record?->must_change_password ? 'Noch offen: Die Person hat das vergebene Passwort noch nicht durch ein eigenes ersetzt. ' : '')
            . 'Leer lassen, um das Passwort nicht zu ändern. Ein neues muss die Person beim nächsten Anmelden durch ein eigenes ersetzen.';
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
