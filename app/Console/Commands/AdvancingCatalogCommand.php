<?php

namespace App\Console\Commands;

use App\Support\AdvancingCatalog;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;

/**
 * Advancing-Kataloge einspielen, bis es dafür eine Oberfläche gibt. Die Datei
 * ist JSON {name, intro, structure} (Aufbau: App\Support\AdvancingCatalog), etwa
 * database/advancing/lkh-arena-kultur.json. Gleicher Name ersetzt den Katalog;
 * laufende Advancings behalten ihre Kopie.
 */
class AdvancingCatalogCommand extends Command
{
    protected $signature = 'vc:advancing-catalog {action : import} {file : JSON-Datei, relativ zum Projekt oder absolut}';

    protected $description = 'Spielt einen Advancing-Fragenkatalog aus einer JSON-Datei ein';

    public function handle(): int
    {
        if ($this->argument('action') !== 'import') {
            $this->error('Unbekannte Aktion – möglich ist: import');

            return self::FAILURE;
        }
        $file = (string) $this->argument('file');
        $path = str_starts_with($file, '/') ? $file : base_path($file);
        if (!is_file($path) || !is_readable($path)) {
            $this->error("Datei nicht gefunden: {$file}");

            return self::FAILURE;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $template = AdvancingCatalog::import(is_array($data) ? $data : []);
        } catch (JsonException $exception) {
            $this->error('Kein gültiges JSON: ' . $exception->getMessage());

            return self::FAILURE;
        } catch (InvalidArgumentException $exception) {
            $this->error('Katalog nicht eingespielt:');
            foreach (explode("\n", $exception->getMessage()) as $line) {
                $this->line("  – {$line}");
            }

            return self::FAILURE;
        }

        $questions = count($template->questions());
        $infos = collect((array) $template->structure)->flatMap(fn (array $section): array => (array) ($section['items'] ?? []))
            ->where('type', 'info')->count();
        $this->info("Katalog „{$template->name}“ importiert: {$questions} Fragen, {$infos} Infotexte.");

        return self::SUCCESS;
    }
}
