<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Schutzsperre: RefreshDatabase leert vor jedem Test die Datenbank. Das darf
     * nur die SQLite-Datenbank im Speicher aus phpunit.xml treffen, nie MySQL.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $connection = config('database.default');
        if ($connection !== 'sqlite' || config("database.connections.{$connection}.database") !== ':memory:') {
            throw new RuntimeException(
                'Tests laufen nur gegen SQLite im Speicher (phpunit.xml) – RefreshDatabase würde sonst echte Daten löschen.'
            );
        }
    }

    /**
     * @param  array<string, string>  $permissions  Bereich => none|read|edit
     * @param  array<string, string>  $calendars  Kalender => none|read|edit
     */
    protected function role(string $slug, array $permissions = [], bool $super = false, array $calendars = []): Role
    {
        return Role::create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'is_super' => $super,
            'permissions' => $permissions,
            'calendar_permissions' => $calendars,
        ]);
    }

    protected function userWith(Role ...$roles): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(collect($roles)->pluck('id'));

        return $user->fresh();
    }

    /** Admin der Halle: Vollzugriff auf alle Bereiche, aber nicht System-Admin. */
    protected function admin(): User
    {
        $role = Role::query()->where('is_super', true)->where('is_system_admin', false)->first() ?? $this->role('admin', super: true);

        return $this->userWith($role);
    }

    /** Betreiber: alles wie Admin, dazu Module, Rollen & Rechte und System-Admin-Konten. */
    protected function systemAdmin(): User
    {
        return $this->userWith(Role::systemAdmin());
    }
}
