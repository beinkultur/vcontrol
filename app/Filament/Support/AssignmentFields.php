<?php

namespace App\Filament\Support;

use App\Enums\AssignmentRole;
use App\Models\Employee;
use App\Models\Event;
use App\Models\Trade;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Rollen am Event als Auswahlfelder. Wert ist „typ:id“ – typ wie in der
 * Morph-Map (employee, trade, user). Gespeichert wird über
 * saveRelationshipsUsing, also erst nachdem das Event selbst gespeichert ist.
 */
final class AssignmentFields
{
    private const TYPES = ['employee', 'trade', 'user'];

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
            ->options(fn (?Event $record): array => self::options($record?->assignments->firstWhere('role', $role)))
            ->searchable()
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
     * Mitarbeiter inzwischen fehlt, das Gewerk archiviert oder der Benutzer inaktiv ist.
     *
     * @return array<string, array<string, string>>
     */
    private static function options(mixed $current): array
    {
        $options = [
            'Mitarbeiter' => Employee::query()->orderBy('last_name')->get()
                ->mapWithKeys(fn (Employee $e): array => ['employee:' . $e->id => $e->fullName()])->all(),
            'Gewerke' => Trade::query()->where('is_archived', false)->orderBy('name')->get()
                ->mapWithKeys(fn (Trade $t): array => ['trade:' . $t->id => $t->displayName()])->all(),
            'Benutzer' => User::query()->where('is_active', true)->orderBy('last_name')->get()
                ->mapWithKeys(fn (User $u): array => ['user:' . $u->id => $u->getFilamentName()])->all(),
        ];

        if ($current !== null) {
            $group = ['employee' => 'Mitarbeiter', 'trade' => 'Gewerke', 'user' => 'Benutzer'][$current->assignee_type] ?? 'Mitarbeiter';
            $options[$group][$current->assignee_type . ':' . $current->assignee_id] ??= $current->assigneeName();
        }

        return $options;
    }

    private static function save(Event $event, AssignmentRole $role, ?string $state, mixed $startsAt, mixed $endsAt): void
    {
        [$type, $id] = array_pad(explode(':', (string) $state, 2), 2, null);
        if (!in_array($type, self::TYPES, true) || (int) $id <= 0) {
            $event->assignments()->where('role', $role->value)->delete();

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
