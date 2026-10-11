<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Support\Backups;
use Illuminate\Console\Command;

/** Sicherung von Hand über die Server-Konsole, sonst wie der nächtliche Lauf (App\Support\Backups). */
class RunBackup extends Command
{
    protected $signature = 'vc:backup {--dateien : auch die Dateien, sonst nur sonntags oder wenn die letzte älter als eine Woche ist}';

    protected $description = 'Sichert Datenbank (und Dateien) in die Nextcloud-Datei-Ablage';

    public function handle(): int
    {
        $runs = Backups::run('konsole', $this->option('dateien') ? true : null);
        if ($runs === null) {
            $this->error('Es läuft schon eine Sicherung.');

            return self::FAILURE;
        }

        foreach ($runs as $run) {
            $run->status === BackupRun::OK
                ? $this->info("{$run->kindLabel()}: {$run->file_name} ({$run->sizeLabel()})")
                : $this->error("{$run->kindLabel()}: {$run->error}");
        }

        return $runs->contains('status', BackupRun::FAILED) ? self::FAILURE : self::SUCCESS;
    }
}
