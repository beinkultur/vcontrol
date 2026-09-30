<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

/**
 * Rendert als Admin jede Seite des Panels mit den echten Daten: Listen, Anlegen
 * und je Resource einige Datensätze in Ansicht und Bearbeitung. Findet Fehler,
 * die erst mit Altwerten aus dem Import auftreten und in den Tests (leere
 * SQLite-Datenbank) nicht vorkommen. Liest nur, schreibt nichts.
 */
class SmokeRender extends Command
{
    protected $signature = 'vc:smoke
        {--records=3 : Datensätze je Resource, jeweils die neuesten}
        {--only= : nur Pfade, die diesen Text enthalten}';

    protected $description = 'Rendert alle Seiten als Admin mit echten Daten und meldet Fehler';

    public function handle(Kernel $kernel): int
    {
        // Die Anfragen brauchen keine gespeicherte Session.
        config(['session.driver' => 'array']);

        $panel = Filament::getPanel('app');
        Filament::setCurrentPanel($panel);
        $admin = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('is_super', true))
            ->first();
        if ($admin === null) {
            $this->error('Kein aktiver Admin – erst `php artisan vc:import` ausführen.');

            return self::FAILURE;
        }

        $rows = [];
        $failures = 0;
        foreach ($this->paths($panel) as $path) {
            if (filled($this->option('only')) && !str_contains($path, (string) $this->option('only'))) {
                continue;
            }

            $started = microtime(true);
            [$status, $size, $error] = $this->render($kernel, $path, $admin);
            $ms = (int) round((microtime(true) - $started) * 1000);

            $failures += $status === 200 ? 0 : 1;
            $rows[] = [$path, $status, $ms, round($size / 1024), $error ?? ''];
        }

        $this->table(['Seite', 'Status', 'ms', 'KB', 'Fehler'], $rows);

        if ($failures > 0) {
            $this->error("{$failures} von " . count($rows) . ' Seiten fehlerhaft');

            return self::FAILURE;
        }
        $this->info(count($rows) . ' Seiten fehlerfrei');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function paths(\Filament\Panel $panel): array
    {
        $paths = [];
        foreach ($panel->getResources() as $resource) {
            foreach (array_keys($resource::getPages()) as $name) {
                if (in_array($name, ['index', 'create'], true)) {
                    $paths[] = $resource::getUrl($name, isAbsolute: false);

                    continue;
                }
                foreach ($this->samples($resource::getModel()) as $record) {
                    $paths[] = $resource::getUrl($name, ['record' => $record], isAbsolute: false);
                }
            }
        }
        foreach ($panel->getPages() as $page) {
            $paths[] = $page::getUrl(isAbsolute: false);
        }
        // Seiten außerhalb des Panels (routes/web.php)
        foreach ($this->samples(Event::class) as $event) {
            $paths[] = route('events.guest-list-print', $event, absolute: false);
            $paths[] = route('events.stage-plan-print', $event, absolute: false);
            $paths[] = route('events.stage-plan-svg', $event, absolute: false);
        }

        return array_values(array_unique($paths));
    }

    /**
     * Die neuesten Datensätze plus der älteste: Alt- und Neudaten unterscheiden
     * sich im Import am stärksten.
     *
     * @param  class-string<Model>  $model
     * @return list<Model>
     */
    private function samples(string $model): array
    {
        $key = (new $model)->getKeyName();
        $records = $model::query()->orderByDesc($key)->limit((int) $this->option('records'))->get();
        $oldest = $model::query()->orderBy($key)->first();
        if ($oldest !== null) {
            $records->push($oldest);
        }

        return $records->unique($key)->values()->all();
    }

    /** @return array{int, int, ?string} Status, Größe in Byte, Fehlermeldung */
    private function render(Kernel $kernel, string $path, User $user): array
    {
        $request = Request::create(rtrim((string) config('app.url'), '/') . $path, 'GET');
        app()->instance('request', $request);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app('auth')->forgetGuards();
        app('auth')->guard('web')->setUser($user);

        try {
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
        } catch (Throwable $e) {
            return [0, 0, $this->describe($e)];
        }

        $exception = $response->exception ?? null;

        return [
            $response->getStatusCode(),
            strlen((string) $response->getContent()),
            $exception instanceof Throwable ? $this->describe($exception) : null,
        ];
    }

    private function describe(Throwable $e): string
    {
        $file = str_replace(base_path() . '/', '', $e->getFile());

        return class_basename($e) . ': ' . mb_strimwidth($e->getMessage(), 0, 160, '…') . " ({$file}:{$e->getLine()})";
    }
}
