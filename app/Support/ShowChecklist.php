<?php

namespace App\Support;

/**
 * Prüfpunkte der Durchführungs-Checkliste wie die „EventsCheckliste“ in AppSheet
 * (CHECK01–13): Name und Frage. Die PHP-Version hat dafür nur einen Platzhalter.
 */
final class ShowChecklist
{
    /** @var array<string, array<string, array{0: string, 1: ?string}>> Gruppe => Schlüssel => [Name, Frage] */
    public const GROUPS = [
        'Material' => [
            'emergency_case' => ['Emergency Case', 'vollständig?'],
            'barrier_case' => ['Barriercase', 'vollständig?'],
            'production_case' => ['Produktionscase', 'vollständig?'],
            'impact_wrench' => ['Schlagschrauber', 'zugänglich?'],
            'barriers' => ['Barriers', 'zurück?'],
            'railing_bolts' => ['Geländerschrauben', null],
            'bus_power' => ['Busstrom', 'abgeschaltet?'],
            'backstages' => ['Backstages', 'gecheckt?'],
        ],
        'Kleinteile' => [
            'washers_small' => ['Unterlegscheiben klein', null],
            'washers_large' => ['Unterlegscheiben groß', null],
            'screws_short' => ['Schrauben kurz (Bühne)', null],
            'screws_long' => ['Schrauben lang (Bühne)', null],
            'railing_screws' => ['Geländerschrauben', null],
        ],
    ];

    /** @return array<string, string> Schlüssel => Name (mit Frage) */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::GROUPS as $items) {
            foreach ($items as $key => [$name, $question]) {
                $labels[$key] = $question === null ? $name : "{$name} {$question}";
            }
        }

        return $labels;
    }

    /**
     * @param  array<string, array{value?: ?string, note?: ?string}>|null  $checks
     * @return array{done: int, missing: int, total: int}
     */
    public static function counts(?array $checks): array
    {
        $values = collect(array_keys(self::labels()))->map(fn (string $key): ?string => $checks[$key]['value'] ?? null);

        return [
            'done' => $values->filter(fn (?string $value): bool => $value === 'yes')->count(),
            'missing' => $values->filter(fn (?string $value): bool => $value === 'no')->count(),
            'total' => $values->count(),
        ];
    }
}
