<?php

namespace App\Support;

use App\Models\EventStage;

/**
 * Podeste einer Bühne wie in der PHP-Version (EventStageSummary): Grundfläche
 * in ganzen Metern ÷ 2 für Hauptbühne, beide Wings und das Rollipodest, dazu
 * sonstige Podeste. Bestand, Mietanteil, Rollipodest und Höhen stellt jede
 * Halle selbst ein (StageSettings).
 */
final class StagePodests
{
    /**
     * @param  array<string, mixed>  $stage  Werte wie in event_stages
     * @return array{main: int, wing_sl: int, wing_sr: int, rollpodest: int, other: int, total: int}
     */
    public static function calculate(array $stage): array
    {
        $main = self::fromArea($stage['width'] ?? null, $stage['depth'] ?? null);
        $wingSl = self::fromArea($stage['wing_sl_width'] ?? null, $stage['wing_sl_depth'] ?? null);
        $wingSr = self::fromArea($stage['wing_sr_width'] ?? null, $stage['wing_sr_depth'] ?? null);
        // Ohne Angabe steht das Rollipodest in Standardgröße; 0 heißt: keins.
        $settings = StageSettings::current();
        $roll = self::fromArea(
            self::meters($stage['rollpodest_width'] ?? null) ?? $settings->rollWidth,
            self::meters($stage['rollpodest_depth'] ?? null) ?? $settings->rollDepth,
        );
        $other = max(0, self::meters($stage['extra_platforms'] ?? null) ?? 0);

        return [
            'main' => $main,
            'wing_sl' => $wingSl,
            'wing_sr' => $wingSr,
            'rollpodest' => $roll,
            'other' => $other,
            'total' => $main + $wingSl + $wingSr + $roll + $other,
        ];
    }

    public static function inventory(): int
    {
        return StageSettings::current()->inventory;
    }

    public static function includedInRent(): int
    {
        return StageSettings::current()->included;
    }

    /** @return list<float> Bühnenhöhen in Metern, die die Halle stellen kann */
    public static function heights(): array
    {
        return StageSettings::current()->heightChoices();
    }

    /** Für die Abrechnung: Podeste über dem im Mietpreis enthaltenen Kontingent. */
    public static function billableExtra(?int $total): int
    {
        return max(0, ($total ?? 0) - self::includedInRent());
    }

    /** Die gespeicherte Summe, sonst die berechnete. */
    public static function total(?EventStage $stage): ?int
    {
        if ($stage === null) {
            return null;
        }
        if ($stage->podest_total !== null) {
            return (int) $stage->podest_total;
        }
        $total = self::calculate($stage->getAttributes())['total'];

        return $total > 0 ? $total : null;
    }

    /**
     * Kurzform für Listen wie in der PHP-Version, z. B. „14×8 H1 62P“. Auffällig
     * sind eine andere als die Standardhöhe und mehr Podeste als im Bestand.
     *
     * @return array{text: string, alert: bool, height: bool, podests: bool}
     */
    public static function summary(?EventStage $stage): array
    {
        if ($stage === null) {
            return ['text' => '–', 'alert' => false, 'height' => false, 'podests' => false];
        }

        $parts = [];
        $width = self::meters($stage->width);
        $depth = self::meters($stage->depth);
        if ($width > 0 && $depth > 0) {
            $parts[] = "{$width}×{$depth}";
        }
        $height = $stage->height === null ? null : (float) $stage->height;
        if ($height > 0) {
            $parts[] = 'H' . self::formatMeters($height);
        }
        $total = self::total($stage);
        if ($total > 0) {
            $parts[] = "{$total}P";
        }

        $heightAlert = $height !== null && abs($height - StageSettings::current()->height) >= 0.011;
        $podestAlert = $total !== null && $total > self::inventory();

        return [
            'text' => $parts === [] ? '–' : implode(' ', $parts),
            'alert' => $heightAlert || $podestAlert,
            'height' => $heightAlert,
            'podests' => $podestAlert,
        ];
    }

    /** 1.4 → „1,4“, 1.0 → „1“ */
    public static function formatMeters(float $meters): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($meters, 2, '.', ''), '0'), '.'));
    }

    private static function fromArea(mixed $width, mixed $depth): int
    {
        $width = self::meters($width);
        $depth = self::meters($depth);
        if ($width === null || $depth === null || $width <= 0 || $depth <= 0) {
            return 0;
        }

        return intdiv($width * $depth, 2);
    }

    private static function meters(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round((float) $value);
    }
}
