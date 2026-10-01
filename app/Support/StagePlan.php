<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventStage;

/**
 * Bühnenplan (Draufsicht) aus der PHP-Version (StagePlan), fast unverändert.
 * Blick von der Bühne zum Publikum: SL = rechts im Plan, SR = links im Plan.
 * Maße in Metern, im SVG mal SCALE.
 *
 * Standardbühne, Hausbestand, Raumbegrenzung und Beschriftung stellt jede Halle
 * ein (StageSettings, voreingestellt die Inselpark Arena: 14 × 8 m, 24 + 2).
 */
final class StagePlan
{
    /**
     * Rahmen des Plans, fest für alle Hallen: keine echte Raumgröße, sondern das
     * Seitenverhältnis von Bühne zu Plan.
     */
    public const ROOM_WIDTH = 25;

    public const ROOM_DEPTH = 15;

    /** Ein Podest: 2 × 1 m */
    public const PODEST_W = 2;

    public const PODEST_D = 1;

    public const STAIR_H = 1.0;

    public const MARGIN_DOWNSTAGE = 4.0;

    public const MARGIN_UPSTAGE = 0.5;

    public const DEFAULT_BACKWALL_CM = 160;

    public const DEFAULT_WING_OFFSET = 1;

    public const SCALE = 14;

    public const FOOTER_H = 72;

    /** @return array<string, mixed> */
    public static function build(?EventStage $stage, Event $event): array
    {
        $values = $stage?->getAttributes() ?? [];
        $settings = StageSettings::current();
        $baseW = $settings->baseWidth;
        $baseD = $settings->baseDepth;

        $reqW = max($baseW, self::intVal($values['width'] ?? null) ?? $baseW);
        $reqD = max($baseD, self::intVal($values['depth'] ?? null) ?? $baseD);
        if (($reqW - $baseW) % 2 !== 0) {
            $reqW = $baseW + (int) (floor(($reqW - $baseW) / 2) * 2);
        }

        $wingSlW = self::intVal($values['wing_sl_width'] ?? null);
        $wingSlD = self::intVal($values['wing_sl_depth'] ?? null);
        $wingSrW = self::intVal($values['wing_sr_width'] ?? null);
        $wingSrD = self::intVal($values['wing_sr_depth'] ?? null);
        $wingSlOff = max(0, self::intVal($values['wing_sl_offset'] ?? null) ?? self::DEFAULT_WING_OFFSET);
        $wingSrOff = max(0, self::intVal($values['wing_sr_offset'] ?? null) ?? self::DEFAULT_WING_OFFSET);
        $backwallM = self::backwallMeters($values['backwall_cm'] ?? null);
        $stairSlOff = max(0, self::intVal($values['stair_sl_offset'] ?? null) ?? 0);
        $stairSrOff = max(0, self::intVal($values['stair_sr_offset'] ?? null) ?? 0);

        $calc = StagePodests::calculate($values);
        $inventory = StagePodests::inventory();
        $available = max(0, $inventory - $calc['rollpodest']);

        $platforms = [];
        $roomCx = self::ROOM_WIDTH / 2;
        $stageDownY = self::resolveStageDownY($reqD, $backwallM, $wingSlW, $wingSlD, $wingSlOff, $wingSrW, $wingSrD, $wingSrOff);
        $stageLeft = $roomCx - $reqW / 2;
        $coreLeft = $roomCx - $baseW / 2;
        $sideCols = (int) (($reqW - $baseW) / 2);
        $extraDepthDown = max(0, $reqD - $baseD);
        $standardFrontY = $stageDownY - $extraDepthDown;

        // Standardbühne (14 × 8): mittlerer und hinterer (upstage) Teil
        for ($row = 0; $row < $baseD; $row++) {
            for ($col = 0; $col < intdiv($baseW, self::PODEST_W); $col++) {
                $platforms[] = self::podest($coreLeft + $col * self::PODEST_W, $standardFrontY - ($row + 1) * self::PODEST_D, self::PODEST_W, self::PODEST_D, 'main', false, true);
            }
        }

        // Tiefer als die Standardbühne: nur downstage (Richtung Publikum) auf deren Breite
        for ($row = 0; $row < $extraDepthDown; $row++) {
            for ($col = 0; $col < intdiv($baseW, self::PODEST_W); $col++) {
                $platforms[] = self::podest($coreLeft + $col * self::PODEST_W, $stageDownY - ($row + 1) * self::PODEST_D, self::PODEST_W, self::PODEST_D, 'main_extension', false, false);
            }
        }

        for ($s = 0; $s < $sideCols; $s++) {
            $platforms = array_merge(
                $platforms,
                self::tileStrip1m($coreLeft - ($s + 1), $stageDownY, $reqD, 'extension'),
                self::tileStrip1m($coreLeft + $baseW + $s, $stageDownY, $reqD, 'extension'),
            );
        }

        if ($wingSlW && $wingSlD) {
            $platforms = array_merge($platforms, self::tileRect($stageLeft + $reqW, $stageDownY - $wingSlOff, $wingSlW, $wingSlD, 'wing_sl'));
        }
        if ($wingSrW && $wingSrD) {
            $platforms = array_merge($platforms, self::tileRect($stageLeft - $wingSrW, $stageDownY - $wingSrOff, $wingSrW, $wingSrD, 'wing_sr'));
        }

        self::assignPlatformTiers($platforms, $settings->house2x1, $settings->house1x1);

        $constructionUpY = self::constructionUpY($platforms);
        $height = isset($values['height']) && $values['height'] !== '' ? (float) $values['height'] : $settings->height;
        $stairW = max(0.2, round($height - 0.2, 2));

        // SL = rechts im Plan, SR = links im Plan
        $stairs = [
            ['id' => 'upstage_right', 'x' => $stageLeft + $reqW, 'y' => $constructionUpY + $stairSlOff, 'w' => $stairW, 'h' => self::STAIR_H, 'symbol' => '←'],
            ['id' => 'upstage_left', 'x' => $stageLeft - $stairW, 'y' => $constructionUpY + $stairSrOff, 'w' => $stairW, 'h' => self::STAIR_H, 'symbol' => '→'],
        ];

        $tierCounts = ['standard' => 0, 'in_house' => 0, 'rented' => 0];
        foreach ($platforms as $p) {
            $tierCounts[$p['tier']]++;
        }

        return [
            'platforms' => $platforms,
            'stairs' => $stairs,
            'room' => [
                'width' => self::ROOM_WIDTH,
                'depth' => self::ROOM_DEPTH,
                'center_x' => $roomCx,
                'boundary' => $settings->boundary,
                'boundary_left' => $roomCx - $settings->boundary,
                'boundary_right' => $roomCx + $settings->boundary,
            ],
            // Für Legende und Zahlen: Standardbühne und Hausbestand dieser Halle
            'base' => ['width' => $baseW, 'depth' => $baseD, 'podests' => $settings->basePodests()],
            'house' => ['2x1' => $settings->house2x1, '1x1' => $settings->house1x1],
            'stage' => [
                'width' => $reqW,
                'depth' => $reqD,
                'left' => $stageLeft,
                'down_y' => $stageDownY,
                'up_y' => $stageDownY - $reqD,
                'height' => $height,
                'construction_up_y' => $constructionUpY,
                'backwall_y' => $constructionUpY - $backwallM,
                'backwall_cm' => (int) round($backwallM * 100),
            ],
            'stats' => [
                'plan_used' => $calc['main'] + $calc['wing_sl'] + $calc['wing_sr'],
                'available' => $available,
                'inventory' => $inventory,
                'roll_reserved' => $calc['rollpodest'],
                'extra_other' => $calc['other'],
                'total_used' => $calc['total'],
                'additional' => max(0, $calc['total'] - $inventory),
                'over_limit' => $calc['total'] > $inventory,
                'tier_counts' => $tierCounts,
            ],
            'title' => trim((string) $event->title) ?: 'Event',
            'headline' => trim(($event->starts_at?->format('d.m.Y') ?? '') . ' ' . $event->title),
            'notes' => trim((string) ($values['stage_notes'] ?? '')),
            'subtitle' => self::subtitle($settings->label, $reqW, $reqD, $height, $wingSlW, $wingSlD, $wingSrW, $wingSrD),
        ];
    }

    /** @param  array<string, mixed>  $plan */
    public static function toSvg(array $plan): string
    {
        $s = self::SCALE;
        $w = self::ROOM_WIDTH * $s;
        $roomH = self::ROOM_DEPTH * $s;
        $totalH = $roomH + self::FOOTER_H;
        $cx = $plan['room']['center_x'] * $s;
        $boundL = $plan['room']['boundary_left'] * $s;
        $boundR = $plan['room']['boundary_right'] * $s;
        $backwallY = $plan['stage']['backwall_y'] * $s;
        $constructionUpY = $plan['stage']['construction_up_y'] * $s;
        $dimX = ($plan['stage']['left'] + $plan['stage']['width'] + 1.5) * $s;
        $lineEndY = max(0, (int) $backwallY);

        $svg = sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" class="vc-plan-svg" width="100%%" preserveAspectRatio="xMidYMid meet">', $w, $totalH);
        $svg .= '<rect width="100%" height="100%" fill="#fff"/>';
        $svg .= sprintf('<rect x="0" y="0" width="%d" height="%d" fill="none" stroke="#cbd5e1" stroke-width="1"/>', $w, $roomH);

        // Raumbegrenzung (grün, Inselpark Arena ±10 m), Raummitte (rot), Rückwand (schwarz)
        foreach ([$boundL, $boundR] as $bx) {
            $svg .= sprintf('<line x1="%.1f" y1="%d" x2="%.1f" y2="%d" stroke="#16a34a" stroke-width="1" stroke-dasharray="6 4"/>', $bx, $lineEndY, $bx, $roomH);
        }
        $svg .= sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#dc2626" stroke-width="1" stroke-dasharray="4 3"/>', (int) $cx, $lineEndY, (int) $cx, $roomH);
        $svg .= sprintf('<line x1="0" y1="%.1f" x2="%d" y2="%.1f" stroke="#000" stroke-width="2"/>', $backwallY, $w, $backwallY);

        // Bemaßung Konstruktion – Rückwand
        $svg .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#000" stroke-width="0.75"/>', $dimX, $constructionUpY, $dimX, $backwallY);
        $svg .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#000" stroke-width="0.75"/>', $dimX - 3, $constructionUpY, $dimX + 3, $constructionUpY);
        $svg .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#000" stroke-width="0.75"/>', $dimX - 3, $backwallY, $dimX + 3, $backwallY);
        $midY = ($constructionUpY + $backwallY) / 2;
        $svg .= sprintf('<text x="%.1f" y="%.1f" text-anchor="middle" font-size="4" fill="#000" transform="rotate(-90, %.1f, %.1f)">%d</text>', $dimX + 8, $midY, $dimX + 8, $midY, $plan['stage']['backwall_cm']);

        foreach ($plan['platforms'] as $p) {
            $svg .= self::podestSvg($p);
        }
        foreach ($plan['stairs'] as $stair) {
            $svg .= self::stairSvg($stair);
        }

        $exitY = $roomH / 2;
        $svg .= sprintf('<text x="%.1f" y="%.1f" font-size="6" fill="#16a34a" text-anchor="middle" transform="rotate(-90, %.1f, %.1f)">EMERGENCY EXIT</text>', $boundL - 6, $exitY, $boundL - 6, $exitY);
        $svg .= sprintf('<text x="%.1f" y="%.1f" font-size="6" fill="#16a34a" text-anchor="middle" transform="rotate(90, %.1f, %.1f)">EMERGENCY EXIT</text>', $boundR + 6, $exitY, $boundR + 6, $exitY);
        $svg .= sprintf('<text x="%d" y="%d" text-anchor="middle" font-size="6" fill="#475569">↓ Publikum</text>', (int) ($cx + $w * 0.20), $roomH - 6);

        // Fußzeile: Legende links, Titel und Zahlen rechts daneben
        $stats = $plan['stats'];
        $tiers = $stats['tier_counts'];
        $y0 = $roomH + 22;
        $textX = 128;
        $svg .= self::legendItem(1.0, $y0, '#d4d4d4', '#737373', sprintf('standard stage %dx%d', $plan['base']['width'], $plan['base']['depth']));
        $svg .= self::legendItem(1.0, $y0 + 9, '#93c5fd', '#2563eb', sprintf('up to %d additional platforms (charged additionally)', $plan['house']['2x1']));
        $svg .= self::legendItem(1.0, $y0 + 18, '#fdba74', '#ea580c', 'must be rented additionally (at your own expense)');
        $svg .= self::legendItem(1.0, $y0 + 27, '#c4b5fd', '#6d28d9', 'stairs');

        $svg .= sprintf('<text x="%d" y="%d" font-size="7" font-weight="700" fill="#000">%s</text>', $textX, (int) $y0, e($plan['headline']));
        $svg .= sprintf(
            '<text x="%d" y="%d" font-size="5" fill="#000">%d gesamt · %d Standard · %d in house · %d angemietet</text>',
            $textX,
            (int) ($y0 + 11),
            $stats['plan_used'],
            $tiers['standard'],
            $tiers['in_house'],
            $tiers['rented'],
        );
        if ($plan['notes'] !== '') {
            $svg .= sprintf('<text x="%d" y="%d" font-size="5" font-style="italic" fill="#000">%s</text>', $textX, (int) ($y0 + 21), e($plan['notes']));
        }
        if ($stats['over_limit']) {
            $svg .= sprintf('<text x="%d" y="%d" text-anchor="end" font-size="8" font-weight="700" fill="#dc2626">+%d angemietet nötig</text>', $w - 8, (int) ($y0 + 21), $stats['additional']);
        }

        return $svg . '</svg>';
    }

    /** Dateiname und Titel beim Drucken, wie in der PHP-Version. */
    public static function documentTitle(Event $event): string
    {
        return ($event->starts_at ?? now())->format('Ymd') . ' ' . trim((string) $event->title) . ' – Bühnenplan – VenueControl';
    }

    /**
     * Standard, Hausbestand (die ersten n Zusatzpodeste) oder angemietet.
     *
     * @param  list<array<string, mixed>>  $platforms
     */
    private static function assignPlatformTiers(array &$platforms, int $house21, int $house11): void
    {
        foreach ($platforms as &$p) {
            if ($p['standard_slot']) {
                $p['tier'] = 'standard';

                continue;
            }
            if ($p['w'] == 1.0 && $p['h'] == 1.0) {
                $p['tier'] = $house11-- > 0 ? 'in_house' : 'rented';

                continue;
            }
            $p['tier'] = $house21-- > 0 ? 'in_house' : 'rented';
        }
        unset($p);
    }

    /** @param  list<array<string, mixed>>  $platforms */
    private static function constructionUpY(array $platforms): float
    {
        return $platforms === [] ? 0.0 : (float) min(array_column($platforms, 'y'));
    }

    /** @return list<array<string, mixed>> */
    private static function tileRect(float $left, float $frontY, int $widthM, int $depthM, string $type): array
    {
        $out = [];
        $fullCols = intdiv($widthM, self::PODEST_W);

        for ($row = 0; $row < $depthM; $row++) {
            for ($col = 0; $col < $fullCols; $col++) {
                $out[] = self::podest($left + $col * self::PODEST_W, $frontY - ($row + 1) * self::PODEST_D, self::PODEST_W, self::PODEST_D, $type, false, false);
            }
        }
        if ($widthM % self::PODEST_W === 1) {
            $out = array_merge($out, self::tileStrip1m($left + $fullCols * self::PODEST_W, $frontY, $depthM, $type));
        }

        return $out;
    }

    /** Streifen von 1 m Breite: gedrehte 2×1-Podeste, am Ende ggf. ein 1×1. @return list<array<string, mixed>> */
    private static function tileStrip1m(float $x, float $frontY, int $depthM, string $type): array
    {
        $out = [];
        $y = $frontY;
        for ($remaining = $depthM; $remaining > 0;) {
            $size = $remaining >= 2 ? 2 : 1;
            $out[] = self::podest($x, $y - $size, 1, $size, $type, $size === 2, false);
            $y -= $size;
            $remaining -= $size;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function podest(float $x, float $y, float $w, float $h, string $type, bool $rotated, bool $standardSlot): array
    {
        return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'type' => $type, 'rotated' => $rotated, 'standard_slot' => $standardSlot];
    }

    private static function resolveStageDownY(int $reqD, float $backwallM, ?int $wingSlW, ?int $wingSlD, int $wingSlOff, ?int $wingSrW, ?int $wingSrD, int $wingSrOff): float
    {
        $upReach = $reqD;
        if ($wingSlW && $wingSlD) {
            $upReach = max($upReach, $wingSlOff + $wingSlD);
        }
        if ($wingSrW && $wingSrD) {
            $upReach = max($upReach, $wingSrOff + $wingSrD);
        }

        return max($upReach + $backwallM + self::MARGIN_UPSTAGE, self::ROOM_DEPTH - self::MARGIN_DOWNSTAGE);
    }

    /** @param  array<string, mixed>  $p */
    private static function podestSvg(array $p): string
    {
        [$fill, $stroke] = match ($p['tier']) {
            'in_house' => ['#93c5fd', '#2563eb'],
            'rented' => ['#fdba74', '#ea580c'],
            default => ['#d4d4d4', '#737373'],
        };

        return sprintf(
            '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s" stroke="%s" stroke-width="0.75"/>',
            $p['x'] * self::SCALE,
            $p['y'] * self::SCALE,
            $p['w'] * self::SCALE,
            $p['h'] * self::SCALE,
            $fill,
            $stroke,
        );
    }

    /** @param  array<string, mixed>  $stair */
    private static function stairSvg(array $stair): string
    {
        $x = $stair['x'] * self::SCALE;
        $y = $stair['y'] * self::SCALE;
        $w = $stair['w'] * self::SCALE;
        $h = $stair['h'] * self::SCALE;
        $fontSize = max(5, min(9, (int) round(min($w, $h) * 0.55)));

        return sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#c4b5fd" stroke="#6d28d9" stroke-width="1" rx="1"/>', $x, $y, $w, $h)
            . sprintf(
                '<text x="%.1f" y="%.1f" text-anchor="middle" dominant-baseline="middle" font-size="%d" font-weight="700" fill="#4c1d95">%s</text>',
                $x + $w / 2,
                $y + $h / 2,
                $fontSize,
                e($stair['symbol']),
            );
    }

    private static function legendItem(float $x, float $y, string $fill, string $stroke, string $label): string
    {
        return sprintf(
            '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s" stroke="%s" stroke-width="0.6"/><text x="%.1f" y="%.1f" font-size="3.5" fill="#475569">%s</text>',
            $x,
            $y - 4.5,
            6.0,
            6.0,
            $fill,
            $stroke,
            $x + 10,
            $y,
            e($label),
        );
    }

    private static function backwallMeters(mixed $value): float
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_BACKWALL_CM / 100;
        }

        return max(0, (int) round((float) str_replace(',', '.', (string) $value))) / 100;
    }

    private static function subtitle(string $label, int $w, int $d, float $h, ?int $wslW, ?int $wslD, ?int $wsrW, ?int $wsrD): string
    {
        $parts = [sprintf('%s %d×%d×%.1fm', $label, $w, $d, $h)];
        if ($wslW && $wslD) {
            $parts[] = sprintf('wing SL %d×%d', $wslW, $wslD);
        }
        if ($wsrW && $wsrD) {
            $parts[] = sprintf('wing SR %d×%d', $wsrW, $wsrD);
        }

        return implode(' + ', $parts);
    }

    private static function intVal(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) round((float) $value);
    }
}
