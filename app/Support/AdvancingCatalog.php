<?php

namespace App\Support;

use App\Models\AdvancingTemplate;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Aufbau eines Advancing-Fragenkatalogs und sein Import.
 *
 * Struktur: Liste von Abschnitten {title, text?, items}. Ein Eintrag ist ein
 * Infotext {type: info, label?, text?} oder eine Frage {key, type, label, help?,
 * required?, locked?, options? (nur choice), binding?}. Der key ist im Katalog
 * eindeutig und bleibt stabil – Vorbelegung und Antworten hängen daran.
 * `locked`: der Veranstalter sieht die Frage nur. `binding`: Vorbelegung aus dem
 * Event (App\Support\Advancings::prefillFromEvent).
 */
final class AdvancingCatalog
{
    public const TYPES = ['text', 'textarea', 'number', 'date', 'choice', 'yes_no', 'person', 'info'];

    public const BINDINGS = [
        'event.title', 'event.date',
        'schedule.get_in', 'schedule.load_in', 'schedule.admission', 'schedule.start_time', 'schedule.end_time', 'schedule.curfew', 'schedule.load_out',
        'onsite.name', 'onsite.email', 'onsite.phone',
    ];

    private const KEY_PATTERN = '/^[a-z0-9_]{1,60}$/';

    /**
     * Was am Aufbau nicht stimmt; leer heißt gültig.
     *
     * @param  array<mixed>  $structure
     * @return list<string>
     */
    public static function errors(array $structure): array
    {
        if (!array_is_list($structure) || $structure === []) {
            return ['Der Katalog braucht eine Liste von Abschnitten.'];
        }
        $errors = [];
        $keys = [];
        foreach ($structure as $s => $section) {
            $where = 'Abschnitt ' . ($s + 1);
            if (!is_array($section) || !isset($section['items']) || !is_array($section['items']) || !array_is_list($section['items']) || $section['items'] === []) {
                $errors[] = "{$where}: braucht eine Liste „items“ mit mindestens einem Eintrag.";

                continue;
            }
            foreach ($section['items'] as $i => $item) {
                $at = "{$where}, Eintrag " . ($i + 1);
                if (!is_array($item)) {
                    $errors[] = "{$at}: kein gültiger Eintrag.";

                    continue;
                }
                $type = $item['type'] ?? null;
                if (!in_array($type, self::TYPES, true)) {
                    $errors[] = "{$at}: unbekannter Typ „" . (is_scalar($type) ? $type : '?') . '“.';

                    continue;
                }
                if ($type === 'info') {
                    if (blank($item['label'] ?? null) && blank($item['text'] ?? null)) {
                        $errors[] = "{$at}: Infotext ohne Überschrift und Text.";
                    }

                    continue;
                }
                $key = $item['key'] ?? null;
                if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                    $errors[] = "{$at}: Schlüssel „" . (is_scalar($key) ? $key : '') . '“ ist ungültig (nur a–z, 0–9 und _).';
                } elseif (isset($keys[$key])) {
                    $errors[] = "{$at}: Schlüssel „{$key}“ kommt doppelt vor.";
                } else {
                    $keys[$key] = true;
                }
                if (blank($item['label'] ?? null)) {
                    $errors[] = "{$at}: Frage ohne Beschriftung.";
                }
                if ($type === 'choice' && (!is_array($item['options'] ?? null) || array_filter($item['options'], 'is_string') === [])) {
                    $errors[] = "{$at}: Auswahl „" . ($key ?? '') . '“ ohne Optionen.';
                }
                $binding = $item['binding'] ?? null;
                if ($binding !== null && !in_array($binding, self::BINDINGS, true)) {
                    $errors[] = "{$at}: unbekannte Bindung „" . (is_scalar($binding) ? $binding : '?') . '“.';
                }
            }
        }

        return $errors;
    }

    /**
     * Fragen nach Schlüssel, ohne Infotexte.
     *
     * @param  array<mixed>  $structure
     * @return array<string, array<string, mixed>>
     */
    public static function questions(array $structure): array
    {
        $questions = [];
        foreach ($structure as $section) {
            foreach ((array) ($section['items'] ?? []) as $item) {
                if (is_array($item) && ($item['type'] ?? null) !== 'info' && is_string($item['key'] ?? null)) {
                    $questions[$item['key']] = $item;
                }
            }
        }

        return $questions;
    }

    /**
     * Katalog anlegen oder – gleicher Name – ersetzen. Laufende Advancings haben
     * ihre eigene Kopie und bleiben, wie sie sind.
     *
     * @param  array<mixed>  $data  {name, intro?, structure}
     */
    public static function import(array $data): AdvancingTemplate
    {
        $name = trim((string) ($data['name'] ?? ''));
        $structure = $data['structure'] ?? null;
        $errors = $name === '' ? ['Der Katalog braucht einen Namen.'] : [];
        $errors = [...$errors, ...(is_array($structure) ? self::errors($structure) : ['Der Katalog braucht eine Liste von Abschnitten.'])];
        if ($errors !== []) {
            throw new InvalidArgumentException(implode("\n", $errors));
        }

        return DB::transaction(fn (): AdvancingTemplate => AdvancingTemplate::query()->updateOrCreate(
            ['name' => mb_substr($name, 0, 120)],
            ['intro' => filled($data['intro'] ?? null) ? (string) $data['intro'] : null, 'structure' => $structure],
        ));
    }
}
