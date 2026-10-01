<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Paritätstest für die Rechte: Jede Rolle muss hier dieselben Seiten sehen wie
 * in der PHP-Version. Vergleichsbasis sind die Sollwerte ihres Smoke-Tests
 * (scripts/smoke_baseline.json: Statuscode je Rolle und Seite). Verglichen wird
 * „erreichbar oder nicht“ – die PHP-Version antwortet auf verbotene Seiten teils
 * mit Umleitung, teils mit 403.
 */
class CheckAccess extends Command
{
    protected $signature = 'vc:check-access';

    protected $description = 'Vergleicht die Rechte je Rolle mit den Sollwerten der PHP-Version';

    /** Seite der PHP-Version => Gegenstück hier. Wächst mit jedem portierten Modul. */
    private const PAGES = [
        'GET /events' => '/events',
        'GET /buchhaltung?status=open' => '/buchhaltung',
        'GET /veranstalter' => '/veranstalter',
        'GET /users' => '/benutzer',
        'GET /admin/rollen' => '/rollen',
        'GET /admin/gewerke' => '/gewerke',
        'GET /admin/mitarbeiter' => '/mitarbeiter',
        'GET /admin/raeume' => '/raeume',
        'GET /admin/feldoptionen' => '/feldoptionen',
        'GET /admin/inventar' => '/inventar',
        'GET /admin/kalender' => '/kalender-ebenen',
        'GET /admin/stammdaten' => '/halle',
        'GET /protokolle/uebergabe' => '/uebergabeprotokolle',
        'GET /protokolle/bestellscheine' => '/bestellscheine',
        'GET /protokolle/schaeden' => '/schaeden',
    ];

    public function handle(Kernel $kernel): int
    {
        $baseline = $this->baseline();
        if ($baseline === null) {
            return self::FAILURE;
        }

        $rows = [];
        $mismatches = 0;
        $roles = Role::query()->orderBy('sort_order')->get();

        foreach (self::PAGES as $legacyPage => $path) {
            foreach ($roles as $role) {
                $expected = $baseline[$role->slug . '|' . $legacyPage] ?? null;
                $actual = $this->status($kernel, $path, $this->userWithOnly($role));
                $ok = $expected !== null && (($expected === 200) === ($actual === 200));
                $mismatches += $ok ? 0 : 1;
                $rows[] = [$path, $role->slug, $expected ?? '–', $actual, $ok ? '✓' : '✗'];
            }

            $expected = $baseline['anonym|' . $legacyPage] ?? null;
            $actual = $this->status($kernel, $path, null);
            $ok = $actual === 302;
            $mismatches += $ok ? 0 : 1;
            $rows[] = [$path, 'ohne Anmeldung', $expected ?? '–', $actual, $ok ? '✓' : '✗'];
        }

        $this->table(['Seite', 'Rolle', 'PHP-Version', 'Laravel', ''], $rows);

        if ($mismatches > 0) {
            $this->error("{$mismatches} Abweichung(en) von der PHP-Version");

            return self::FAILURE;
        }
        $this->info(count($rows) . ' Vergleiche, alle wie in der PHP-Version');

        return self::SUCCESS;
    }

    /**
     * Ein echter Benutzer der Rolle, der nur diese eine Rolle hat – wie im
     * Smoke-Test der PHP-Version. Ohne eigenen Benutzer: Identität eines Admins.
     */
    private function userWithOnly(Role $role): User
    {
        $base = User::query()->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereKey($role->getKey()))
            ->first()
            ?? User::query()->whereHas('roles', fn ($query) => $query->where('is_super', true))->firstOrFail();

        $user = User::query()->findOrFail($base->getKey());
        $user->setRelation('roles', new Collection([$role]));

        return $user;
    }

    private function status(Kernel $kernel, string $path, ?User $user): int
    {
        $request = Request::create(rtrim((string) config('app.url'), '/') . $path, 'GET');
        app()->instance('request', $request);
        // Jede Anfrage mit frischer Session: Sonst liegt noch der Passwort-Hash des
        // vorherigen Benutzers darin, und AuthenticateSession meldet ab.
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app('auth')->forgetGuards();
        if ($user !== null) {
            app('auth')->guard('web')->setUser($user);
        }

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response->getStatusCode();
    }

    /** @return array<string, int>|null */
    private function baseline(): ?array
    {
        $path = (string) (config('venuecontrol.legacy_baseline')
            ?: dirname((string) config('venuecontrol.legacy_config'), 2) . '/scripts/smoke_baseline.json');
        if (!is_file($path)) {
            $this->error('Sollwerte der PHP-Version nicht gefunden: ' . $path);

            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data['status'] ?? null)) {
            $this->error('Keine Statuscodes in ' . $path);

            return null;
        }

        return array_map('intval', $data['status']);
    }
}
