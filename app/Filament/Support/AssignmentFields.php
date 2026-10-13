<?php

namespace App\Filament\Support;

use App\Enums\AssignmentRole;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Trade;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Rollen am Event als Auswahlfelder. Wert ist „typ:id“ – typ wie in der
 * Morph-Map (user, contact, trade). Gespeichert wird über
 * saveRelationshipsUsing, also erst nachdem das Event selbst gespeichert ist.
 * Wer Kontakte anlegen darf, legt einen neuen direkt im Feld an.
 */
final class AssignmentFields
{
    private const TYPES = ['user', 'contact', 'trade'];

    private const GROUPS = ['user' => 'Benutzer', 'contact' => 'Kontakte', 'trade' => 'Gewerke'];

    /**
     * Je Rolle eine Zeile: wer, von, bis.
     *
     * @param  list<AssignmentRole>  $except  Rollen, die woanders stehen (Projektleitung unter Buchung › Daten)
     * @return list<Grid>
     */
    public static function all(array $except = []): array
    {
        $roles = array_filter(AssignmentRole::cases(), fn (AssignmentRole $role): bool => !in_array($role, $except, true));

        return array_values(array_map(fn (AssignmentRole $role): Grid => Grid::make(4)->schema([
            self::select($role)->columnSpan(2),
            self::time($role, 'starts_at', 'von'),
            self::time($role, 'ends_at', 'bis'),
        ]), $roles));
    }

    /** Uhrzeit der Rolle; gespeichert zusammen mit der Zuordnung, ohne sie leer. */
    private static function time(AssignmentRole $role, string $column, string $label): TimePicker
    {
        return TimePicker::make("role_{$role->value}_{$column}")
            ->label($label)
            ->seconds(false)
            ->afterStateHydrated(function (TimePicker $component, ?Event $record) use ($role, $column): void {
                $value = $record?->assignments->firstWhere('role', $role)?->{$column};
                $component->state($value === null ? null : substr((string) $value, 0, 5));
            })
            ->dehydrated(false);
    }

    /** Nur die Auswahl, ohne Uhrzeiten – für die Projektleitung unter Buchung › Daten. */
    public static function select(AssignmentRole $role): Select
    {
        return Select::make('role_' . $role->value)
            ->label($role->getLabel())
            ->options(fn (?Event $record): array => self::options($record?->assignments->firstWhere('role', $role), $role))
            ->searchable()
            ->createOptionForm(Gate::allows('create', Contact::class) ? fn (): array => self::contactForm($role) : null)
            ->createOptionUsing(fn (array $data): string => 'contact:' . Contact::create([
                'name' => trim((string) preg_replace('/\s+/u', ' ', (string) $data['name'])),
                'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
                'email' => filled($data['email'] ?? null) ? trim((string) $data['email']) : null,
                'positions' => array_values((array) ($data['positions'] ?? [])),
            ])->id)
            ->createOptionModalHeading('Neuer Kontakt')
            ->afterStateHydrated(function (Select $component, ?Event $record) use ($role): void {
                $assignment = $record?->assignments->firstWhere('role', $role);
                $component->state($assignment ? $assignment->assignee_type . ':' . $assignment->assignee_id : null);
            })
            ->dehydrated(false)
            ->saveRelationshipsUsing(fn (Event $record, ?string $state, Get $get) => self::save(
                $record,
                $role,
                $state,
                $get("role_{$role->value}_starts_at"),
                $get("role_{$role->value}_ends_at"),
            ));
    }

    /**
     * Gruppierte Auswahl; die aktuelle Zuordnung bleibt wählbar, auch wenn der
     * Kontakt inzwischen archiviert, das Gewerk archiviert oder der Benutzer
     * inaktiv ist. System-Admins stehen nur für System-Admins zur Wahl. Je Rolle
     * nur die Typen aus AssignmentRole::assigneeTypes(); Benutzer nur als
     * Personen-Konto (Mitarbeiter der Venue, Freelancer) – außer bei der
     * Projektleitung, die jedes Konto übernehmen kann. Mit Voraussetzung
     * (qualification(), etwa VfV oder VL) nur passende Benutzer und Kontakte
     * (Position) und Gewerke (Leistungsbereich).
     *
     * @return array<string, array<string, string>>
     */
    private static function options(mixed $current, AssignmentRole $role): array
    {
        $viewer = Auth::user();
        $types = $role->assigneeTypes();
        $qualification = $role->qualification();
        $qualifies = fn (mixed $values): bool => $qualification === null
            || collect((array) $values)->contains(fn (mixed $value): bool => mb_strtolower(trim((string) $value)) === mb_strtolower($qualification));
        $options = [
            'Benutzer' => !in_array('user', $types, true) ? [] : User::query()->where('is_active', true)->visibleTo($viewer instanceof User ? $viewer : null)
                ->orderBy('last_name')->orderBy('first_name')->get()
                ->filter(fn (User $u): bool => $role === AssignmentRole::ProjectLead || (($u->account_type?->hasPositions() ?? false) && $qualifies($u->positionList())))
                ->mapWithKeys(fn (User $u): array => ['user:' . $u->id => $u->getFilamentName()])->all(),
            'Kontakte' => !in_array('contact', $types, true) ? [] : Contact::query()->where('is_archived', false)->orderBy('name')->get()
                ->filter(fn (Contact $c): bool => $qualifies($c->positions))
                ->mapWithKeys(fn (Contact $c): array => ['contact:' . $c->id => (string) $c->name])->all(),
            'Gewerke' => !in_array('trade', $types, true) ? [] : Trade::query()->where('is_archived', false)->orderBy('name')->get()
                ->filter(fn (Trade $t): bool => $qualifies($t->categories))
                ->mapWithKeys(fn (Trade $t): array => ['trade:' . $t->id => $t->displayName()])->all(),
        ];

        if ($current !== null) {
            $group = self::GROUPS[$current->assignee_type] ?? 'Kontakte';
            $options[$group][$current->assignee_type . ':' . $current->assignee_id] ??= $current->assigneeName();
        }

        return array_filter($options);
    }

    /**
     * Neuer Kontakt direkt aus dem Feld: Person oder Funktion, mit Kontaktdaten
     * fürs Daysheet; die Position der Rolle (VL, VfV) ist vorbelegt.
     *
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Field>
     */
    private static function contactForm(AssignmentRole $role): array
    {
        return [
            TextInput::make('name')
                ->label('Name')
                ->helperText('Person oder Funktion, z. B. „Hallenmeister“.')
                ->required()
                ->maxLength(160),
            Grid::make(2)->schema([
                TextInput::make('phone')->label('Telefon')->tel()->maxLength(50),
                TextInput::make('email')->label('E-Mail')->email()->maxLength(255),
            ]),
            CheckboxList::make('positions')
                ->label('Positionen')
                ->helperText($role->qualification() !== null ? 'Für ' . $role->getLabel() . ' braucht der Kontakt die Position ' . $role->qualification() . '.' : null)
                ->options(ContactResource::positionOptions(null))
                ->default($role->qualification() !== null ? [$role->qualification()] : [])
                ->columns(3),
        ];
    }

    private static function save(Event $event, AssignmentRole $role, ?string $state, mixed $startsAt, mixed $endsAt): void
    {
        [$type, $id] = array_pad(explode(':', (string) $state, 2), 2, null);
        if (!in_array($type, self::TYPES, true) || (int) $id <= 0) {
            // Einzeln statt per Abfrage, damit das Änderungsprotokoll es sieht
            $event->assignments()->where('role', $role->value)->get()->each->delete();

            return;
        }

        $event->assignments()->updateOrCreate(
            ['role' => $role->value],
            ['assignee_type' => $type, 'assignee_id' => (int) $id, 'starts_at' => self::clock($startsAt), 'ends_at' => self::clock($endsAt)],
        );
    }

    /** „8:00“, „08:00“ oder „08:00:00“ → „08:00:00“; alles andere → leer. */
    private static function clock(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value, $m) === 1
            ? sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2])
            : null;
    }
}
