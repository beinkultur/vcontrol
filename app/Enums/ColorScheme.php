<?php

namespace App\Enums;

use App\Models\Setting;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasLabel;

/**
 * Farbschema der Oberfläche, wählbar unter Verwaltung › System (nur
 * System-Admins). Ohne Einstellung gilt VC Basic.
 *
 * colors(): Farben für Filament – Knöpfe, Navigation, Hinweise, Grautöne
 * (AppPanelProvider), je als ganze Skala von 50 bis 950.
 *
 * variables(): die eigenen Farben von VenueControl als CSS-Variablen – die drei
 * Phasen (public/css/vcontrol.css) und der Akzent der Event-Infos für Externe
 * (public/css/sheet.css, auch auf der Daysheet-Seite ohne Filament). Die Werte
 * stehen fest im Code, nichts davon kommt von Benutzern.
 *
 * stylesheet(): was sich nicht über Farben allein machen lässt, etwa die dunkle
 * Kopfleiste von Lila-Grün (public/css/scheme-lila-gruen.css) oder Schrift und
 * Formen der Website bei VC Basic (public/css/scheme-vc-basic.css).
 */
enum ColorScheme: string implements HasLabel
{
    /** Blau der Website www.vcontrol.eu: 600 = #2563eb (Knöpfe, Links), 700 = #1d4ed8 (darüberfahren) */
    private const WEBSITE_BLUE = [
        50 => '#eff6ff',
        100 => '#dbeafe',
        200 => '#bfdbfe',
        300 => '#93c5fd',
        400 => '#60a5fa',
        500 => '#3b82f6',
        600 => '#2563eb',
        700 => '#1d4ed8',
        800 => '#1e40af',
        900 => '#1e3a8a',
        950 => '#172554',
    ];

    /** Der Look der Website www.vcontrol.eu: Blau, kühles Blaugrau bis Navy (seit 14.10.2026) */
    case VcBasic = 'vc-basic';

    /** Die Palette des T1X-Backends (App\Support\T1XPalette im T1X-Repo) */
    case LilaGruen = 'lila-gruen';

    public static function current(): self
    {
        return self::tryFrom((string) Setting::lookup(Setting::COLOR_SCHEME)) ?? self::VcBasic;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::VcBasic => 'VC Basic',
            self::LilaGruen => 'Lila-Grün',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::VcBasic => 'Blau mit kühlem Blaugrau und Systemschrift – wie die Website www.vcontrol.eu.',
            self::LilaGruen => 'Lila mit Grün, grünlichem Grau und dunkler Kopfleiste – die Farben von T1X.',
        };
    }

    /**
     * Alle Farben, die Filament kennt – auch die unveränderten, damit beim
     * Wechsel nichts vom anderen Schema stehen bleibt.
     *
     * @return array<string, array<int, string>>
     */
    public function colors(): array
    {
        return match ($this) {
            // Blau und Bernstein genau wie auf der Website (dort die Werte von 600 und
            // 700), Grau aus deren Tönen: 50 Abschnittsgrund, 200 Linien, 500
            // gedämpfte Schrift, 700 Fließtext, 950 Navy der Überschriften
            self::VcBasic => [
                'primary' => self::WEBSITE_BLUE,
                'gray' => [
                    50 => '#f4f7fb',
                    100 => '#edf1f7',
                    200 => '#e3e8ef',
                    300 => '#cbd3df',
                    400 => '#9aa4b5',
                    500 => '#6b7587',
                    600 => '#515c70',
                    700 => '#3b4659',
                    800 => '#26324a',
                    900 => '#182540',
                    950 => '#0f1d3a',
                ],
                'info' => self::WEBSITE_BLUE,
                'success' => Color::Green,
                'warning' => [
                    50 => '#fffbeb',
                    100 => '#fef3c7',
                    200 => '#fde68a',
                    300 => '#fcd34d',
                    400 => '#fbbf24',
                    500 => '#f59e0b',
                    600 => '#d97706',
                    700 => '#b45309',
                    800 => '#92400e',
                    900 => '#78350f',
                    950 => '#451a03',
                ],
                'danger' => Color::Red,
            ],
            self::LilaGruen => [
                'primary' => self::shadesLikeFilament3('#660B64'),
                'gray' => [
                    50 => '#F2F5F4',
                    100 => '#E3E8E6',
                    200 => '#CDD4D1',
                    300 => '#B3BCB9',
                    400 => '#8E9A97',
                    500 => '#707C7A',
                    600 => '#5A6462',
                    700 => '#454D4B',
                    800 => '#2E3534',
                    900 => '#232827',
                    950 => '#1A2421',
                ],
                'info' => Color::Blue,
                'success' => [
                    50 => '#EAFBF7',
                    100 => '#D2F5EC',
                    200 => '#A8EAD9',
                    300 => '#79DEC4',
                    400 => '#5CD3B5',
                    500 => '#46C7AF',
                    600 => '#51877D',
                    700 => '#3E6B63',
                    800 => '#2F514B',
                    900 => '#213935',
                    950 => '#182A27',
                ],
                'warning' => self::shadesLikeFilament3('#F6C876'),
                'danger' => self::shadesLikeFilament3('#E45247'),
            ],
        };
    }

    /**
     * Skala aus einer Farbe so, wie Filament 3 sie bildet – damit sieht es aus
     * wie im T1X-Backend (Filament 3). 500 ist die Farbe selbst, darüber zu Weiß
     * gemischt, darunter abgedunkelt. Filament 5 nähme nur den Farbton und
     * machte aus dem tiefen T1X-Lila ein helles Orchidee mit dunkler Schrift.
     *
     * @return array<int, string>
     */
    private static function shadesLikeFilament3(string $hex): array
    {
        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x');
        $shades = [];
        foreach ([50 => 0.95, 100 => 0.9, 200 => 0.75, 300 => 0.6, 400 => 0.3, 500 => 1.0, 600 => 0.9, 700 => 0.75, 800 => 0.6, 900 => 0.49, 950 => 0.3] as $shade => $intensity) {
            $mix = fn (int $channel): int => (int) round($shade < 500 ? (255 - $channel) * $intensity + $channel : $channel * $intensity);
            $shades[$shade] = sprintf('#%02x%02x%02x', $mix($red), $mix($green), $mix($blue));
        }

        return $shades;
    }

    /**
     * Phasen nur im Panel (dort gibt es Filaments Variablen wie --primary-600),
     * der Akzent auch auf der Daysheet-Seite – dort deshalb als feste Farbe.
     * --vc-accent-dark gilt nur im Dunkelmodus des Panels. *-ink ist die Schrift
     * auf der Phasenfarbe (Nummern, aktiver Unterbereich): auf hellem Grün und
     * Gold dunkel, sonst weiß.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        return match ($this) {
            self::VcBasic => [
                '--vc-buchung' => '#2563eb',
                '--vc-planung' => '#0d9488',
                '--vc-durchfuehrung' => '#d97706',
                '--vc-buchung-ink' => '#fff',
                '--vc-planung-ink' => '#fff',
                '--vc-durchfuehrung-ink' => '#fff',
                '--vc-accent' => '#2563eb',
                '--vc-accent-dark' => '#60a5fa',
            ],
            self::LilaGruen => [
                '--vc-buchung' => 'var(--primary-600)',
                '--vc-planung' => 'var(--success-500)',
                '--vc-durchfuehrung' => 'var(--warning-500)',
                '--vc-buchung-ink' => '#fff',
                '--vc-planung-ink' => 'var(--gray-950)',
                '--vc-durchfuehrung-ink' => 'var(--gray-950)',
                '--vc-accent' => '#660B64',
                '--vc-accent-dark' => 'var(--primary-400)',
            ],
        };
    }

    /**
     * Farbmuster für die Auswahl: Hauptfarbe, Grün, Gelb, Rot, Grau.
     *
     * @return list<string>
     */
    public function swatches(): array
    {
        $colors = $this->colors();
        $shade = fn (string $name, int $shade): string => (string) $colors[$name][$shade];

        return [$shade('primary', 600), $shade('success', 500), $shade('warning', 500), $shade('danger', 600), $shade('gray', 700)];
    }

    /** Eigene Stile des Schemas (Pfad unter public/), etwa die dunkle Kopfleiste von Lila-Grün. */
    public function stylesheet(): ?string
    {
        return match ($this) {
            self::VcBasic => 'css/scheme-vc-basic.css',
            self::LilaGruen => 'css/scheme-lila-gruen.css',
        };
    }

    /** Die Variablen als CSS für ein <style>-Element. */
    public function css(): string
    {
        return ':root{' . collect($this->variables())
            ->map(fn (string $value, string $name): string => "{$name}:{$value};")
            ->implode('') . '}';
    }
}
