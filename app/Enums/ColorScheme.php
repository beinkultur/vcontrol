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
 * (AppPanelProvider). Filament bildet aus einer einzelnen Farbe eine ganze
 * Skala im Farbton dieser Farbe.
 *
 * variables(): die eigenen Farben von VenueControl als CSS-Variablen – die drei
 * Phasen (public/css/vcontrol.css) und der Akzent der Event-Infos für Externe
 * (public/css/sheet.css, auch auf der Daysheet-Seite ohne Filament). Die Werte
 * stehen fest im Code, nichts davon kommt von Benutzern.
 */
enum ColorScheme: string implements HasLabel
{
    /** Das ursprüngliche Aussehen: Blau, neutrales Grau */
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
            self::VcBasic => 'Blau mit neutralem Grau – das bisherige Aussehen.',
            self::LilaGruen => 'Lila mit Grün und grünlichem Grau – die Farben des T1X-Backends.',
        };
    }

    /**
     * Alle Farben, die Filament kennt – auch die unveränderten, damit beim
     * Wechsel nichts vom anderen Schema stehen bleibt.
     *
     * @return array<string, string|array<int, string>>
     */
    public function colors(): array
    {
        return match ($this) {
            self::VcBasic => [
                'primary' => Color::Blue,
                'gray' => Color::Zinc,
                'info' => Color::Blue,
                'success' => Color::Green,
                'warning' => Color::Amber,
                'danger' => Color::Red,
            ],
            self::LilaGruen => [
                'primary' => '#660B64',
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
                'warning' => '#F6C876',
                'danger' => '#E45247',
            ],
        };
    }

    /**
     * Phasen nur im Panel (dort gibt es Filaments Variablen wie --primary-600),
     * der Akzent auch auf der Daysheet-Seite – dort deshalb als feste Farbe.
     * --vc-accent-dark gilt nur im Dunkelmodus des Panels.
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
                '--vc-accent' => '#2563eb',
                '--vc-accent-dark' => '#60a5fa',
            ],
            self::LilaGruen => [
                '--vc-buchung' => 'var(--primary-600)',
                '--vc-planung' => 'var(--success-600)',
                '--vc-durchfuehrung' => 'var(--warning-600)',
                '--vc-accent' => '#660B64',
                '--vc-accent-dark' => 'var(--primary-400)',
            ],
        };
    }

    /**
     * Farbmuster für die Auswahl: Hauptfarbe, Grün, Gelb, Rot, Grau – so, wie
     * Filament sie anzeigt (aus einer einzelnen Farbe wird eine Skala).
     *
     * @return list<string>
     */
    public function swatches(): array
    {
        $colors = $this->colors();
        $shade = fn (string $name, int $shade): string => (string) (is_string($colors[$name]) ? Color::generatePalette($colors[$name]) : $colors[$name])[$shade];

        return [$shade('primary', 600), $shade('success', 500), $shade('warning', 500), $shade('danger', 600), $shade('gray', 700)];
    }

    /** Die Variablen als CSS für ein <style>-Element. */
    public function css(): string
    {
        return ':root{' . collect($this->variables())
            ->map(fn (string $value, string $name): string => "{$name}:{$value};")
            ->implode('') . '}';
    }
}
