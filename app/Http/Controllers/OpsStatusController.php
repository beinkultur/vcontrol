<?php

namespace App\Http\Controllers;

use App\Models\BackupRun;
use App\Support\Backups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Statusauskunft für das Cockpit (ops.bein.ws, Vertrag in dessen
 * docs/ops-status-snippet.md), dazu der Stand der nächtlichen Sicherung für
 * den Wochenbericht. Ohne oder mit falschem Token 404 – für Unbefugte gibt es
 * die Route nicht. Keine Seiteneffekte, keine Geheimnisse in der Antwort.
 */
class OpsStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('venuecontrol.ops_status_token');
        abort_if($expected === '' || !hash_equals($expected, (string) $request->header('X-Ops-Token')), 404);

        // Vom Deploy-Skript geschrieben: „<commit> <1 = mit lokalen Änderungen>“
        $revision = is_file(base_path('REVISION')) ? explode(' ', trim((string) file_get_contents(base_path('REVISION')))) : [];

        return response()->json([
            'version' => null,
            'commit' => $revision[0] ?? null,
            'branch' => null,
            'dirty' => isset($revision[1]) ? $revision[1] === '1' : null,
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'migrations_pending' => $this->pendingMigrations(),
            'db_bytes' => $this->databaseBytes(),
            'env' => app()->environment(),
            'backup' => [
                'database_ok_at' => Backups::lastOk(BackupRun::DATABASE)?->started_at?->format('d.m.Y H:i'),
                'files_ok_at' => Backups::lastOk(BackupRun::FILES)?->started_at?->format('d.m.Y H:i'),
                'warning' => Backups::warning(),
            ],
        ], 200, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    private function pendingMigrations(): ?int
    {
        try {
            Artisan::call('migrate:status', ['--pending' => true]);

            return substr_count(Artisan::output(), 'Pending');
        } catch (Throwable) {
            return null;
        }
    }

    private function databaseBytes(): ?int
    {
        try {
            $row = DB::selectOne(
                'SELECT SUM(data_length + index_length) AS bytes FROM information_schema.TABLES WHERE table_schema = ?',
                [DB::connection()->getDatabaseName()],
            );

            return $row?->bytes === null ? null : (int) $row->bytes;
        } catch (Throwable) {
            return null;
        }
    }
}
