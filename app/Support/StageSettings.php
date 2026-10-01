<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Bühnen-Stammdaten dieser Halle, einstellbar unter Verwaltung › Halle.
 * Voreingestellt sind die Werte der Inselpark Arena (in der PHP-Version fest im
 * Code). Fest bleibt nur der Rahmen des Bühnenplans, siehe StagePlan::ROOM_WIDTH.
 *
 * Einmal je Anfrage gelesen (scoped im Container, siehe AppServiceProvider);
 * nach dem Speichern forget() aufrufen.
 */
final class StageSettings
{
    /** Schlüssel in settings => Voreinstellung */
    public const DEFAULTS = [
        Setting::PODEST_INVENTORY => 86,  // 56 Standardbühne + 24 Zusatzpodeste + 6 Rollipodest
        Setting::PODEST_INCLUDED => 62,   // im Mietpreis: 56 Standardbühne + 6 Rollipodest
        'stage_base_width' => 14,
        'stage_base_depth' => 8,
        'stage_house_2x1' => 24,
        'stage_house_1x1' => 2,
        'stage_roll_width' => 4,
        'stage_roll_depth' => 3,
        'stage_height' => 1.4,
        'stage_heights' => '0.4;0.6;1;1.4',
        'stage_boundary' => 10.0,
        'stage_label' => 'IPA stage',
    ];

    /** @param  list<float>  $heights */
    public function __construct(
        public readonly int $inventory,
        public readonly int $included,
        public readonly int $baseWidth,
        public readonly int $baseDepth,
        public readonly int $house2x1,
        public readonly int $house1x1,
        public readonly int $rollWidth,
        public readonly int $rollDepth,
        public readonly float $height,
        public readonly array $heights,
        public readonly float $boundary,
        public readonly string $label,
    ) {
    }

    public static function current(): self
    {
        return app(self::class);
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::class);
    }

    public static function load(): self
    {
        $stored = Setting::query()->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')
            ->filter(fn (?string $value): bool => $value !== null && trim($value) !== '');
        $value = fn (string $key): mixed => $stored->get($key, self::DEFAULTS[$key]);

        return new self(
            inventory: (int) $value(Setting::PODEST_INVENTORY),
            included: (int) $value(Setting::PODEST_INCLUDED),
            baseWidth: (int) $value('stage_base_width'),
            baseDepth: (int) $value('stage_base_depth'),
            house2x1: (int) $value('stage_house_2x1'),
            house1x1: (int) $value('stage_house_1x1'),
            rollWidth: (int) $value('stage_roll_width'),
            rollDepth: (int) $value('stage_roll_depth'),
            height: (float) $value('stage_height'),
            heights: self::parseHeights((string) $value('stage_heights')) ?: self::parseHeights(self::DEFAULTS['stage_heights']),
            boundary: (float) $value('stage_boundary'),
            label: (string) $value('stage_label'),
        );
    }

    /** Podeste (2 × 1 m) der Standardbühne, bei 14 × 8 m also 56. */
    public function basePodests(): int
    {
        return intdiv($this->baseWidth, 2) * $this->baseDepth;
    }

    /** Podeste des Rollipodests in Standardgröße, bei 4 × 3 m also 6. */
    public function rollPodests(): int
    {
        return intdiv($this->rollWidth * $this->rollDepth, 2);
    }

    /**
     * Wählbare Bühnenhöhen, die Standardhöhe immer dabei.
     *
     * @return list<float>
     */
    public function heightChoices(): array
    {
        $heights = array_values(array_unique([...$this->heights, $this->height], SORT_REGULAR));
        sort($heights);

        return $heights;
    }

    /**
     * „0.4;0.6;1;1.4“ oder „0,4 / 1,4“ → [0.4, 1.4]
     *
     * @return list<float>
     */
    public static function parseHeights(string $value): array
    {
        $heights = [];
        foreach (preg_split('/[;\/\s]+/', $value) ?: [] as $part) {
            $height = round((float) str_replace(',', '.', $part), 2);
            if ($height > 0) {
                $heights[] = $height;
            }
        }
        $heights = array_values(array_unique($heights, SORT_REGULAR));
        sort($heights);

        return $heights;
    }

    /** @param  list<float>  $heights */
    public static function formatHeights(array $heights): string
    {
        return implode(';', array_map(fn (float $height): string => rtrim(rtrim(number_format($height, 2, '.', ''), '0'), '.'), $heights));
    }
}
