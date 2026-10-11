<?php

namespace App\Http\Controllers;

use App\Models\BackupRun;
use App\Support\Backups;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /cron/sicherung?key=…: ruft der KAS-Cronjob jede Nacht auf (All-Inkl kennt
 * kein crontab, nur URL-Aufrufe). Antwortet mit einer Zeile je Sicherung –
 * 500, wenn eine scheiterte; die Mail an die System-Admins verschickt Backups.
 */
class BackupCronController extends Controller
{
    private const HEADERS = ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow'];

    public function __invoke(Request $request): Response
    {
        if (!Backups::keyMatches($request->query('key'))) {
            return response("Kein Zugriff.\n", 403, self::HEADERS);
        }

        $runs = Backups::run('zeitplan');
        if ($runs === null) {
            return response("Es läuft schon eine Sicherung.\n", 409, self::HEADERS);
        }

        $text = $runs->map(fn (BackupRun $run): string => $run->status === BackupRun::OK
            ? "OK {$run->kindLabel()}: {$run->file_name} ({$run->sizeLabel()})"
            : "FEHLER {$run->kindLabel()}: {$run->error}")->implode("\n");

        return response($text . "\n", $runs->contains('status', BackupRun::FAILED) ? 500 : 200, self::HEADERS);
    }
}
