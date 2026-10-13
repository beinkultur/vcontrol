<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Event;
use App\Models\Promoter;
use App\Models\Trade;
use App\Support\Audit;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Füllt eine leere Installation mit Demodaten (Database\Seeders\DemoSeeder),
 * gedacht für demo.vcontrol.eu. Bricht ab, sobald es Events, Veranstalter,
 * Gewerke oder Kontakte gibt – echte Daten überschreibt der Befehl nie.
 * Ins Änderungsprotokoll kommt ein Eintrag statt Tausender einzelner.
 */
class DemoData extends Command
{
    protected $signature = 'vc:demo-data {--halle=Be-In Arena : Name der erfundenen Halle}';

    protected $description = 'Füllt eine leere Installation mit Demodaten einer erfundenen Halle';

    public function handle(): int
    {
        if (Event::query()->exists() || Promoter::query()->exists() || Trade::query()->exists() || Contact::query()->exists()) {
            $this->error('Hier gibt es schon Daten – Demodaten nur in einer leeren Installation.');

            return self::FAILURE;
        }
        $venue = trim((string) $this->option('halle')) ?: 'Be-In Arena';
        if ($this->input->isInteractive() && !$this->confirm("Demodaten für „{$venue}“ anlegen?", true)) {
            return self::FAILURE;
        }

        DB::transaction(fn () => Audit::quietly(fn () => (new DemoSeeder($venue))->run()));
        Audit::record('demo', Audit::IMPORTED, null, ['halle' => $venue, 'events' => DemoSeeder::eventCount()], label: "Demodaten „{$venue}“");

        $this->info(DemoSeeder::eventCount() . " Events mit Stammdaten für „{$venue}“ angelegt.");
        $this->line('Das erste eigene Konto: php artisan vc:system-admin <E-Mail>');

        return self::SUCCESS;
    }
}
