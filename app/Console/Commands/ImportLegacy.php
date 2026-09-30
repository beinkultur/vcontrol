<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Übernimmt die Daten der PHP-Version (vc.bein.ws). Beliebig oft wiederholbar:
 * Datensätze werden über ihre ID abgeglichen, was es in der Quelle nicht mehr
 * gibt, wird entfernt. Bis zum Umstieg ist die PHP-Version führend.
 */
class ImportLegacy extends Command
{
    protected $signature = 'vc:import';

    protected $description = 'Übernimmt Stammdaten, Rollen mit Rechten, Benutzer und Veranstalter aus der PHP-Version';

    public function handle(): int
    {
        $legacy = $this->legacyConnection();
        if ($legacy === null) {
            return self::FAILURE;
        }

        $this->info('Quelle: ' . $legacy->getDatabaseName());
        DB::transaction(function () use ($legacy): void {
            $this->importCalendars($legacy);
            $this->importSettings($legacy);
            // Vor den Benutzern: users.employee_id und users.trade_id verweisen darauf
            $this->importEmployees($legacy);
            $this->importTrades($legacy);
            $this->importRooms($legacy);
            $this->importFieldOptions($legacy);
            $this->importInventory($legacy);
            $this->importRoles($legacy);
            $this->importUsers($legacy);
            $this->importPromoters($legacy);
            $this->importEvents($legacy);
        });

        return self::SUCCESS;
    }

    private function legacyConnection(): ?Connection
    {
        $path = (string) config('venuecontrol.legacy_config');
        if ($path === '' || !is_file($path)) {
            $this->error('VC_LEGACY_CONFIG zeigt nicht auf die config.php der PHP-Version: „' . $path . '“');

            return null;
        }

        $db = (require $path)['db'] ?? null;
        if (!is_array($db) || empty($db['name'])) {
            $this->error('Keine db-Einstellungen in ' . $path);

            return null;
        }

        config(['database.connections.legacy' => [
            'driver' => 'mysql',
            'host' => $db['host'] ?? 'localhost',
            'port' => 3306,
            'database' => $db['name'],
            'username' => $db['user'] ?? '',
            'password' => $db['pass'] ?? '',
            'charset' => $db['charset'] ?? 'utf8mb4',
            'collation' => null,
            'prefix' => '',
            'strict' => true,
        ]]);

        return DB::connection('legacy');
    }

    private function importCalendars(Connection $legacy): void
    {
        $rows = $legacy->table('vc_calendars')->orderBy('sort_order')->get()->map(fn (object $c): array => [
            'key' => $c->key,
            'name' => $c->name,
            'color' => $c->color,
            'freitermin_status' => $c->freitermin_status,
            'is_system' => (bool) $c->is_system,
            'sort_order' => (int) $c->sort_order,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ])->all();

        $this->sync('calendars', $rows, 'key');
    }

    private function importSettings(Connection $legacy): void
    {
        $rows = $legacy->table('vc_settings')->get()->map(fn (object $s): array => [
            'key' => $s->key,
            'value' => $s->value,
            'created_at' => $s->updated_at,
            'updated_at' => $s->updated_at,
        ])->all();

        $this->sync('settings', $rows, 'key');
    }

    private function importEmployees(Connection $legacy): void
    {
        $rows = $legacy->table('vc_employees')->orderBy('id')->get()->map(fn (object $e): array => [
            'id' => $e->id,
            'first_name' => $e->first_name,
            'last_name' => $e->last_name,
            'initials' => $e->initials,
            'phone' => $e->phone,
            'email' => $e->email,
            'positions' => $this->jsonList($e->positions),
            'created_at' => $e->created_at,
            'updated_at' => $e->updated_at,
        ])->all();

        $this->sync('employees', $rows);
    }

    private function importTrades(Connection $legacy): void
    {
        $rows = $legacy->table('vc_trades')->orderBy('id')->get()->map(fn (object $t): array => [
            'id' => $t->id,
            'short_name' => $t->short_name,
            'name' => $t->name,
            'categories' => $this->jsonList($t->category),
            'email' => $t->email,
            'phone' => $t->phone,
            'address1' => $t->address1,
            'address2' => $t->address2,
            'zip' => $t->zip,
            'city' => $t->city,
            'is_archived' => (bool) $t->is_archived,
            'created_at' => $t->created_at,
            'updated_at' => $t->updated_at,
        ])->all();

        $this->sync('trades', $rows);
    }

    private function importRooms(Connection $legacy): void
    {
        $rows = $legacy->table('vc_rooms')->orderBy('id')->get()->map(fn (object $r): array => [
            'id' => $r->id,
            'name' => $r->name,
            'sort_order' => (int) $r->sort_order,
            'is_active' => (bool) $r->active,
            'created_at' => $r->created_at,
            'updated_at' => $r->created_at,
        ])->all();

        $this->sync('rooms', $rows);
    }

    private function importFieldOptions(Connection $legacy): void
    {
        $rows = $legacy->table('vc_field_options')->orderBy('id')->get()->map(fn (object $o): array => [
            'id' => $o->id,
            'field_key' => $o->field_key,
            'value' => $o->value,
            'parent_value' => trim((string) $o->parent_value) === '' ? null : $o->parent_value,
            'sort_order' => (int) $o->sort_order,
            'is_active' => (bool) $o->active,
            'created_at' => $o->created_at,
            'updated_at' => $o->created_at,
        ])->all();

        $this->sync('field_options', $rows);
    }

    private function importInventory(Connection $legacy): void
    {
        $categories = $legacy->table('vc_inventory_categories')->orderBy('id')->get()->map(fn (object $c): array => [
            'id' => $c->id,
            'name' => $c->name,
            'sort_order' => (int) $c->sort_order,
            'is_active' => (bool) $c->active,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ])->all();
        $items = $legacy->table('vc_inventory_items')->orderBy('id')->get()->map(fn (object $i): array => [
            'id' => $i->id,
            'category_id' => $i->category_id,
            'name' => $i->name,
            'is_active' => (bool) $i->active,
            'created_at' => $i->created_at,
            'updated_at' => $i->updated_at,
        ])->all();

        // Verschwundene Artikel zuerst entfernen, sonst blockiert der Fremdschlüssel
        // das Entfernen ihrer Kategorie
        $this->sync('inventory_items', $items, upsert: false);
        $this->sync('inventory_categories', $categories);
        $this->sync('inventory_items', $items);
    }

    /**
     * Event-Kern. Nicht übernommen: legacy_key, calendar_id (IDs eines
     * Google-Kalenders aus AppSheet) und pl (Projektleitung, im Bestand leer).
     */
    private function importEvents(Connection $legacy): void
    {
        $promoterIds = DB::table('promoters')->pluck('id')->flip();

        $rows = $legacy->table('vc_events')->orderBy('id')->get()->map(fn (object $e): array => [
            'id' => $e->id,
            'va_nr' => $e->va_nr,
            'va_id' => $e->va_id,
            'title' => $e->title,
            'promoter_id' => isset($promoterIds[$e->promoter_id]) ? $e->promoter_id : null,
            'status' => $e->status,
            'event_type1' => $e->event_type1,
            'event_type2' => $e->event_type2,
            'starts_at' => $e->starts_at,
            'ends_at' => $e->ends_at,
            'pax_expected' => $e->pax_expected,
            'pax' => $e->pax,
            'areas' => $this->jsonList($e->areas),
            'seating' => $this->jsonList($e->seating),
            'ticketing' => $e->ticketing,
            'wlan' => $e->wlan,
            'wlan_password' => $e->wlan_password,
            'description' => $e->description,
            'booking_notes' => $e->booking_notes,
            'onsite_contact' => $e->onsite_contact,
            'doing_closed' => (bool) $e->doing_closed,
            'closed' => (bool) $e->closed,
            'created_at' => $e->created_at,
            'updated_at' => $e->updated_at,
        ])->all();

        $this->sync('events', $rows);
    }

    /** Komma-Text der PHP-Version („Umbau , Verkehr“) als JSON-Liste. */
    private function jsonList(?string $value): ?string
    {
        $items = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen')));

        return $items === [] ? null : json_encode($items, JSON_UNESCAPED_UNICODE);
    }

    private function importRoles(Connection $legacy): void
    {
        // Die Matrizen liegen in der PHP-Version als Zeilen je Rolle und Bereich
        $permissions = $this->levelsBy($legacy, 'vc_role_permissions', 'role_id', 'area_key');
        $calendars = $this->levelsBy($legacy, 'vc_role_calendar_permissions', 'role_id', 'calendar_key');

        $rows = $legacy->table('vc_roles')->orderBy('id')->get()->map(fn (object $r): array => [
            'id' => $r->id,
            'slug' => $r->slug,
            'name' => $r->name,
            'description' => $r->description,
            'is_system' => (bool) $r->is_system,
            'is_super' => (bool) $r->is_super,
            'sort_order' => (int) $r->sort_order,
            'permissions' => json_encode((object) ($permissions[$r->id] ?? [])),
            'calendar_permissions' => json_encode((object) ($calendars[$r->id] ?? [])),
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ])->all();

        $this->sync('roles', $rows);
    }

    /**
     * Zeilen „Besitzer, Schlüssel, Stufe“ als Matrix je Besitzer.
     *
     * @return array<int, array<string, string>>
     */
    private function levelsBy(Connection $legacy, string $table, string $ownerColumn, string $keyColumn): array
    {
        $matrix = [];
        foreach ($legacy->table($table)->get() as $row) {
            $matrix[(int) $row->{$ownerColumn}][(string) $row->{$keyColumn}] = (string) $row->access_level;
        }

        return $matrix;
    }

    private function importUsers(Connection $legacy): void
    {
        $calendars = $this->levelsBy($legacy, 'vc_user_calendar_permissions', 'user_id', 'calendar_key');

        $rows = $legacy->table('vc_users')->orderBy('id')->get()->map(fn (object $u): array => [
            'id' => $u->id,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'email' => $u->email,
            // bcrypt aus password_hash() – Laravel prüft den Hash unverändert
            'password' => $u->password_hash,
            'account_type' => $u->account_type,
            'employee_id' => $u->employee_id,
            'trade_id' => $u->trade_id,
            'is_active' => (bool) $u->is_active,
            'calendar_permissions' => json_encode((object) ($calendars[$u->id] ?? [])),
            'created_at' => $u->created_at,
            'updated_at' => $u->updated_at,
        ])->all();

        $this->sync('users', $rows);

        $roleIds = DB::table('roles')->pluck('id', 'slug');
        $pairs = [];
        foreach ($legacy->table('vc_user_roles')->get() as $assignment) {
            if (!isset($roleIds[$assignment->role])) {
                $this->warn("  Rolle „{$assignment->role}“ von Benutzer #{$assignment->user_id} gibt es nicht – übersprungen");
                continue;
            }
            $pairs[] = ['role_id' => $roleIds[$assignment->role], 'user_id' => $assignment->user_id];
        }
        DB::table('role_user')->delete();
        DB::table('role_user')->insert($pairs);
        $this->line(sprintf('  %-20s %4d übernommen', 'role_user', count($pairs)));
    }

    private function importPromoters(Connection $legacy): void
    {
        $promoters = [];
        $contacts = [];
        foreach ($legacy->table('vc_promoters')->orderBy('id')->get() as $p) {
            $promoters[] = [
                'id' => $p->id,
                'short_name' => $p->short_name,
                'name' => $p->name,
                'customer_no' => $p->customer_no,
                'email' => $p->email,
                'address1' => $p->address1,
                'address2' => $p->address2,
                'zip' => $p->zip,
                'city' => $p->city,
                'logo_path' => $p->logo_path,
                'is_archived' => (bool) $p->is_archived,
                'created_at' => $p->created_at,
                'updated_at' => $p->updated_at,
            ];

            // Aus den vier festen Spaltengruppen werden einzelne Ansprechpartner
            for ($i = 1; $i <= 4; $i++) {
                $contact = [];
                foreach (['first_name', 'last_name', 'role', 'phone', 'email'] as $field) {
                    $value = trim((string) ($p->{"contact{$i}_{$field}"} ?? ''));
                    $contact[$field] = $value === '' ? null : $value;
                }
                if (array_filter($contact) === []) {
                    continue;
                }
                $contacts[] = $contact + [
                    'promoter_id' => $p->id,
                    'sort_order' => $i,
                    'created_at' => $p->created_at,
                    'updated_at' => $p->updated_at,
                ];
            }
        }

        $this->sync('promoters', $promoters);
        DB::table('promoter_contacts')->delete();
        foreach (array_chunk($contacts, 500) as $chunk) {
            DB::table('promoter_contacts')->insert($chunk);
        }
        $this->line(sprintf('  %-20s %4d übernommen', 'promoter_contacts', count($contacts)));
    }

    /**
     * Spiegelt die Quellzeilen in die Tabelle: anlegen oder aktualisieren über
     * den Schlüssel, Zeilen ohne Gegenstück in der Quelle entfernen.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function sync(string $table, array $rows, string $key = 'id', bool $upsert = true): void
    {
        if ($upsert) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->upsert($chunk, [$key]);
            }
        }
        $keys = array_column($rows, $key);
        $removed = DB::table($table)->whereNotIn($key, $keys === [] ? ['__keiner__'] : $keys)->delete();

        if ($upsert) {
            $this->line(sprintf('  %-20s %4d übernommen%s', $table, count($rows), $removed > 0 ? ", {$removed} entfernt" : ''));
        }
    }
}
