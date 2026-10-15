<?php

namespace App\Console\Commands;

use App\Access\Area;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Paritätstest für die Rechte: Jede Rolle muss hier dieselben Seiten sehen wie
 * in der PHP-Version. Vergleichsbasis sind die Sollwerte ihres Smoke-Tests
 * (scripts/php-smoke-baseline.json, beim Löschen der PHP-Version am 12.10.2026
 * übernommen: Statuscode je Rolle und Seite). Verglichen wird
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
        // Kontakte ersetzen seit 14.10.2026 die Mitarbeiter, gleicher Bereich
        'GET /admin/mitarbeiter' => '/kontakte',
        'GET /admin/raeume' => '/raeume',
        'GET /admin/feldoptionen' => '/feldoptionen',
        'GET /admin/inventar' => '/inventar',
        'GET /admin/kalender' => '/kalender-ebenen',
        'GET /admin/stammdaten' => '/halle',
        'GET /protokolle/uebergabe' => '/uebergabeprotokolle',
        'GET /protokolle/bestellscheine' => '/bestellscheine',
        'GET /protokolle/schaeden' => '/schaeden',
        'GET /codes' => '/codes',
        'GET /audit' => '/audit',
        'GET /extern/events' => '/extern/events',
        'GET /kalender?view=month' => '/kalender?view=month',
        'GET /kalender?view=week' => '/kalender?view=week',
        'GET /kalender?view=day' => '/kalender?view=day',
        'GET /kalender?view=4weeks' => '/kalender?view=4weeks',
        'GET /extern/kalender?view=month' => '/extern/kalender?view=month',
        'GET /extern/kalender?view=week' => '/extern/kalender?view=week',
        'GET /anfragen' => '/anfragen',
        // Seit 15.10.2026: Zählerstände mit Auswertung, Zähler wählbar statt /zaehler/strom usw.
        'GET /zaehler' => '/zaehler',
    ];

    /** Bewusst strenger als die PHP-Version: Diese Seiten erreicht hier nur der System-Admin. */
    private const SYSTEM_ADMIN_ONLY = ['/rollen'];

    /**
     * Bewusst eigener Bereich: Die PHP-Version ließ Anfragen jeden sehen, der
     * Events sehen darf; hier gilt der Bereich „Anfragen“ aus Rollen & Rechte.
     */
    private const OWN_AREA = ['/anfragen' => Area::Anfragen];

    /**
     * Seiten eines Bereichs: Ist er abgeschaltet oder sein Modul nicht
     * freigegeben (Verwaltung › System), erreicht sie niemand.
     */
    private const AREA_PAGES = ['/buchhaltung' => Area::Buchhaltung, '/zaehler' => Area::Zaehler];

    public function handle(Kernel $kernel): int
    {
        // Ohne gespeicherte Sessions: Sonst stünde je Seite und Rolle eine Zeile mit
        // echter Benutzer-ID in `sessions` – und die verrät, ob jemand gearbeitet hat.
        config(['session.driver' => 'array']);

        $baseline = $this->baseline();
        if ($baseline === null) {
            return self::FAILURE;
        }

        $rows = [];
        $mismatches = 0;
        $roles = Role::query()->orderBy('sort_order')->get();
        $off = array_keys(array_filter(self::AREA_PAGES, fn (Area $area): bool => $area->isDisabled()));

        foreach (self::PAGES as $legacyPage => $path) {
            foreach ($roles as $role) {
                // Den System-Admin gibt es nur hier – er erreicht mindestens, was der Admin erreicht
                $expected = match (true) {
                    in_array($path, $off, true) => 403,
                    in_array($path, self::SYSTEM_ADMIN_ONLY, true) => $role->is_system_admin ? 200 : 403,
                    isset(self::OWN_AREA[$path]) => $role->is_super || $role->is_system_admin
                        || in_array($role->permissions[self::OWN_AREA[$path]->value] ?? 'none', ['read', 'edit'], true) ? 200 : 403,
                    default => $baseline[($role->is_system_admin ? 'admin' : $role->slug) . '|' . $legacyPage] ?? null,
                };
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
        $this->info(count($rows) . ' Vergleiche, alle wie in der PHP-Version (bewusst strenger: ' . implode(', ', self::SYSTEM_ADMIN_ONLY) . ' nur für System-Admins, '
            . implode(', ', array_keys(self::OWN_AREA)) . ' nach eigenem Bereich'
            . ($off !== [] ? '; Bereich oder Modul aus, für niemanden: ' . implode(', ', $off) : '') . ')');

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
            ?? User::query()->whereHas('roles', fn ($query) => $query->where('is_super', true)->orWhere('is_system_admin', true))->firstOrFail();

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
        $path = (string) config('venuecontrol.legacy_baseline');
        if (!is_file($path)) {
            $this->error('Sollwerte nicht gefunden: ' . $path);

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
