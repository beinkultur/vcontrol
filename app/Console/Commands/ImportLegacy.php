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

    protected $description = 'Übernimmt Benutzer, Rollen und Veranstalter aus der PHP-Version';

    public function handle(): int
    {
        $legacy = $this->legacyConnection();
        if ($legacy === null) {
            return self::FAILURE;
        }

        $this->info('Quelle: ' . $legacy->getDatabaseName());
        DB::transaction(function () use ($legacy): void {
            $this->importRoles($legacy);
            $this->importUsers($legacy);
            $this->importPromoters($legacy);
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

    private function importRoles(Connection $legacy): void
    {
        $rows = $legacy->table('vc_roles')->orderBy('id')->get()->map(fn (object $r): array => [
            'id' => $r->id,
            'slug' => $r->slug,
            'name' => $r->name,
            'description' => $r->description,
            'is_system' => (bool) $r->is_system,
            'is_super' => (bool) $r->is_super,
            'sort_order' => (int) $r->sort_order,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ])->all();

        $this->sync('roles', $rows);
    }

    private function importUsers(Connection $legacy): void
    {
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
     * die ID, Zeilen ohne Gegenstück in der Quelle entfernen.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function sync(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->upsert($chunk, ['id']);
        }
        $ids = array_column($rows, 'id');
        $removed = DB::table($table)->whereNotIn('id', $ids === [] ? [0] : $ids)->delete();

        $this->line(sprintf('  %-20s %4d übernommen%s', $table, count($rows), $removed > 0 ? ", {$removed} entfernt" : ''));
    }
}
