<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ein Sicherungslauf (App\Support\Backups). Bewusst nicht im Änderungsprotokoll –
 * Backups schreibt die Zeilen ohne Audit, sonst stünden jede Nacht zwei
 * Einträge darin.
 */
#[Fillable(['kind', 'status', 'trigger', 'file_name', 'size_bytes', 'error', 'started_at', 'finished_at'])]
class BackupRun extends Model
{
    public const DATABASE = 'datenbank';

    public const FILES = 'dateien';

    public const RUNNING = 'laeuft';

    public const OK = 'ok';

    public const FAILED = 'fehler';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function kindLabel(): string
    {
        return $this->kind === self::FILES ? 'Dateien' : 'Datenbank';
    }

    public function sizeLabel(): string
    {
        $bytes = (int) $this->size_bytes;

        return $bytes >= 1024 * 1024
            ? number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB'
            : number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.') . ' KB';
    }
}
