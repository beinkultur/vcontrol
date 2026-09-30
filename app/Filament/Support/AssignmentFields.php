<?php

namespace App\Filament\Support;

use App\Enums\AssignmentRole;
use App\Models\Employee;
use App\Models\Event;
use App\Models\Trade;
use App\Models\User;
use Filament\Forms\Components\Select;

/**
 * Rollen am Event als Auswahlfelder. Wert ist „typ:id“ – typ wie in der
 * Morph-Map (employee, trade, user). Gespeichert wird über
 * saveRelationshipsUsing, also erst nachdem das Event selbst gespeichert ist.
 */
final class AssignmentFields
{
    private const TYPES = ['employee', 'trade', 'user'];

    /** @return list<Select> */
    public static function all(): array
    {
        return array_map(fn (AssignmentRole $role): Select => self::field($role), AssignmentRole::cases());
    }

    private static function field(AssignmentRole $role): Select
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
            ->saveRelationshipsUsing(fn (Event $record, ?string $state) => self::save($record, $role, $state));
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

    private static function save(Event $event, AssignmentRole $role, ?string $state): void
    {
        [$type, $id] = array_pad(explode(':', (string) $state, 2), 2, null);
        if (!in_array($type, self::TYPES, true) || (int) $id <= 0) {
            $event->assignments()->where('role', $role->value)->delete();

            return;
        }

        $event->assignments()->updateOrCreate(
            ['role' => $role->value],
            ['assignee_type' => $type, 'assignee_id' => (int) $id],
        );
    }
}
