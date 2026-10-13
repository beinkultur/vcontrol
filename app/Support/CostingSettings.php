<?php

namespace App\Support;

use App\Access\Level;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;

/**
 * Einstellungen der Halle zum Costing (Verwaltung › Halle, Abschnitt Costing):
 * welche Rollen den Menüpunkt Costings und welche das Costing am Event sehen,
 * ob die Ansicht für Externe und das Daysheet das zuletzt verschickte Costing
 * zeigen, und die Vorbelegung der Mail. Admins sehen immer alles. Solange die
 * Rollen nicht gespeichert sind, gilt die erste Regel des Moduls: wer Events
 * bearbeitet oder die Buchhaltung sieht.
 */
final class CostingSettings
{
    public const DEFAULT_SUBJECT = 'Costing {event} – {datum}';

    public const DEFAULT_TEXT = "Hallo,\n\nanbei das Costing für {event} am {datum} in der {halle} ({fassung}, Preisliste {preisliste}).\n\nViele Grüße\n{halle}";

    public const PLACEHOLDER_HELP = '{event}, {datum}, {halle}, {fassung} und {preisliste} werden ersetzt.';

    /** Menüpunkt Costings, also die Liste aller Costings */
    public static function seesMenu(User $user): bool
    {
        return $user->access()->isSuper() || self::hasRole($user, self::menuRoleIds());
    }

    /** Costings sehen – am Event, und wer die Liste hat, auch jedes darin */
    public static function sees(User $user): bool
    {
        return $user->access()->isSuper() || self::hasRole($user, [...self::menuRoleIds(), ...self::eventRoleIds()]);
    }

    /** @return list<int> */
    public static function menuRoleIds(): array
    {
        return self::roleIds(Setting::COSTING_MENU_ROLES);
    }

    /** @return list<int> */
    public static function eventRoleIds(): array
    {
        return self::roleIds(Setting::COSTING_EVENT_ROLES);
    }

    /** Ansicht für Externe („Meine Events“) zeigt das zuletzt verschickte Costing */
    public static function inExtern(): bool
    {
        return Setting::lookup(Setting::COSTING_EXTERN) === '1';
    }

    /** Daysheet zeigt das zuletzt verschickte Costing */
    public static function inDaysheet(): bool
    {
        return Setting::lookup(Setting::COSTING_DAYSHEET) === '1';
    }

    public static function subject(): string
    {
        return Setting::lookup(Setting::COSTING_SUBJECT) ?: self::DEFAULT_SUBJECT;
    }

    public static function text(): string
    {
        return Setting::lookup(Setting::COSTING_TEXT) ?: self::DEFAULT_TEXT;
    }

    /** @return list<string> immer in Kopie */
    public static function bcc(): array
    {
        return Daysheets::emails(explode(',', (string) Setting::lookup(Setting::COSTING_BCC)));
    }

    /** Rollen zur Auswahl: alle außer Admin und System-Admin, die ohnehin alles sehen @return array<int, string> */
    public static function roleOptions(): array
    {
        return Role::query()->where('is_super', false)->where('is_system_admin', false)
            ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @param  list<int>  $menuRoles
     * @param  list<int>  $eventRoles
     * @param  list<string>  $bcc
     */
    public static function save(array $menuRoles, array $eventRoles, bool $extern, bool $daysheet, string $subject, string $text, array $bcc): void
    {
        Setting::put(Setting::COSTING_MENU_ROLES, implode(',', array_unique(array_map('intval', $menuRoles))));
        Setting::put(Setting::COSTING_EVENT_ROLES, implode(',', array_unique(array_map('intval', $eventRoles))));
        Setting::put(Setting::COSTING_EXTERN, $extern ? '1' : '0');
        Setting::put(Setting::COSTING_DAYSHEET, $daysheet ? '1' : '0');
        Setting::put(Setting::COSTING_SUBJECT, trim($subject));
        Setting::put(Setting::COSTING_TEXT, trim(str_replace(["\r\n", "\r"], "\n", $text)));
        $bcc = implode(',', Daysheets::emails($bcc));
        Setting::put(Setting::COSTING_BCC, $bcc !== '' ? $bcc : null);
    }

    /** @return list<int> */
    private static function roleIds(string $key): array
    {
        $stored = Setting::lookup($key);
        if ($stored !== null) {
            return array_values(array_filter(array_map('intval', explode(',', $stored))));
        }

        return Role::query()->where('is_super', false)->where('is_system_admin', false)->get()
            ->filter(fn (Role $role): bool => Level::fromStored($role->permissions['events'] ?? null) === Level::Edit
                || Level::fromStored($role->permissions['buchhaltung'] ?? null)->atLeast(Level::Read))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @param  list<int>  $roleIds */
    private static function hasRole(User $user, array $roleIds): bool
    {
        return $user->roles->contains(fn (Role $role): bool => in_array((int) $role->id, $roleIds, true));
    }
}
