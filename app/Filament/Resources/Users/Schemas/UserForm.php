<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Access\Area;
use App\Enums\AccountType;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\AccessFields;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Trade;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                        Select::make('employee_id')
                            ->label('Mitarbeiter')
                            ->helperText('Verknüpft das Konto mit den Mitarbeiter-Stammdaten: Wo dieser Mitarbeiter im Personal eines Events steht (House Rep., VfV …), ist es ein eigener Dienst – im persönlichen Kalender und unter „Meine Events“.')
                            ->options(fn (?User $record): array => self::employeeOptions($record))
                            ->searchable()
                            ->unique(ignoreRecord: true)
                            ->validationMessages(['unique' => 'Dieser Mitarbeiter ist schon mit einem anderen Konto verknüpft.'])
                            ->live()
                            ->afterStateUpdated(fn (Set $set, mixed $state) => $set('employee_positions', Employee::query()->find($state)?->positions ?? []))
                            ->visible(fn (Get $get): bool => self::type($get)?->linksEmployee() ?? false),
                        CheckboxList::make('employee_positions')
                            ->label('Positionen')
                            ->helperText(fn (): string => 'Für welche Dienste der Mitarbeiter zur Wahl steht (VfV …). Gespeichert beim Mitarbeiter unter Stammdaten › Mitarbeiter'
                                . (self::mayEditEmployees() ? '.' : ' – ändern darf sie, wer Mitarbeiter bearbeiten darf.'))
                            ->options(fn (Get $get): array => EmployeeResource::positionOptions(Employee::query()->find($get('employee_id'))))
                            ->columns(3)
                            ->afterStateHydrated(fn (CheckboxList $component, ?User $record) => $component->state($record?->employee?->positions ?? []))
                            ->dehydrated(false)
                            ->disabled(fn (): bool => !self::mayEditEmployees())
                            ->visible(fn (Get $get): bool => (self::type($get)?->linksEmployee() ?? false) && filled($get('employee_id')))
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
     * Verknüpfung passend zum Kontotyp: Personen-Konten mit einem Mitarbeiter,
     * Gewerk-Accounts mit einem Gewerk; die andere wird gelöst (ausgeblendete
     * Felder speichert Filament nicht mit).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withLinks(array $data): array
    {
        $type = $data['account_type'] ?? null;
        $type = $type instanceof AccountType ? $type : AccountType::tryFrom((string) $type);
        $data['employee_id'] = ($type?->linksEmployee() ?? false) && filled($data['employee_id'] ?? null) ? (int) $data['employee_id'] : null;
        $data['trade_id'] = ($type?->linksTrade() ?? false) && filled($data['trade_id'] ?? null) ? (int) $data['trade_id'] : null;

        return $data;
    }

    /**
     * Positionen beim verknüpften Mitarbeiter speichern – nur wer Mitarbeiter
     * bearbeiten darf, und nur Werte, die zur Wahl stehen.
     *
     * @param  array<string, mixed>  $state  Formularzustand (data)
     */
    public static function savePositions(User $user, array $state): void
    {
        $employee = $user->linkedEmployeeId() !== null ? Employee::query()->find($user->linkedEmployeeId()) : null;
        if ($employee === null || !self::mayEditEmployees() || !array_key_exists('employee_positions', $state)) {
            return;
        }
        $allowed = array_keys(EmployeeResource::positionOptions($employee));
        $positions = array_values(array_intersect($allowed, array_map('strval', (array) $state['employee_positions'])));
        if ($positions !== array_values((array) ($employee->positions ?? []))) {
            $employee->update(['positions' => $positions]);
        }
    }

    private static function type(Get $get): ?AccountType
    {
        $type = $get('account_type');

        return $type instanceof AccountType ? $type : AccountType::tryFrom((string) $type);
    }

    /**
     * Mitarbeiter zur Wahl: nicht archiviert und noch mit keinem anderen Konto
     * verknüpft; der aktuelle bleibt wählbar.
     *
     * @return array<int, string>
     */
    private static function employeeOptions(?User $record): array
    {
        $taken = User::query()->whereNotNull('employee_id')->when($record !== null, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))->pluck('employee_id');

        return Employee::query()
            ->where(fn (Builder $query): Builder => $query->where('is_archived', false)->whereNotIn('id', $taken)->orWhere('id', $record?->employee_id))
            ->orderBy('last_name')->orderBy('first_name')->get()
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => $employee->fullName() . (filled($employee->positions) ? ' (' . implode(', ', $employee->positions) . ')' : '')])
            ->all();
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

    private static function mayEditEmployees(): bool
    {
        return self::actor()?->access()->canEdit(Area::AdminMitarbeiter) ?? false;
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
