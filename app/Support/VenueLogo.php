<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Logo der Halle (Verwaltung › Halle): PNG oder JPG, nicht öffentlich
 * gespeichert. Steht auf dem Costing (CostingPdf), im Kopf der Daysheet-Seite
 * und der Mails (vendor/mail/html/venue); die Halle-Seite zeigt es über die
 * Route venue.logo. SVG ist als Upload gesperrt (App\Support\Uploads).
 */
final class VenueLogo
{
    public const DISK = 'local';

    public const DIRECTORY = 'venue';

    /** Was als Logo durchgeht – am Inhalt erkannt, nicht an der Endung */
    public const TYPES = ['image/png', 'image/jpeg'];

    /** Gespeicherter Pfad, wenn die Datei da und ein Bild ist */
    public static function path(): ?string
    {
        $path = Setting::lookup(Setting::VENUE_LOGO);
        if (blank($path) || !Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return self::mimeType($path) !== null ? $path : null;
    }

    /** @return string|null  image/png oder image/jpeg */
    public static function mimeType(string $path): ?string
    {
        $type = Storage::disk(self::DISK)->mimeType($path);

        return in_array($type, self::TYPES, true) ? $type : null;
    }

    /**
     * Das Bild, eingepasst in die Fläche: Breite und Höhe in deren Einheit
     * (Millimeter fürs PDF, Pixel für Mail und Daysheet), das Seitenverhältnis
     * bleibt. src als data-URI – Dompdf lädt nichts nach, das Daysheet braucht
     * keine Anmeldung; Mails betten content ein.
     *
     * @return array{content: string, mime: string, name: string, src: string, width: float, height: float}|null
     */
    public static function fitted(float $maxWidth, float $maxHeight): ?array
    {
        $path = self::path();
        if ($path === null) {
            return null;
        }
        $content = (string) Storage::disk(self::DISK)->get($path);
        $size = @getimagesizefromstring($content);
        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            return null;
        }
        $scale = min($maxWidth / $size[0], $maxHeight / $size[1]);
        $mime = (string) self::mimeType($path);

        return [
            'content' => $content,
            'mime' => $mime,
            'name' => basename($path),
            'src' => 'data:' . $mime . ';base64,' . base64_encode($content),
            'width' => round($size[0] * $scale, 2),
            'height' => round($size[1] * $scale, 2),
        ];
    }

    /** Neues Logo (oder keins) speichern; das alte verschwindet von der Platte. */
    public static function replace(?string $path): void
    {
        $old = Setting::lookup(Setting::VENUE_LOGO);
        Setting::put(Setting::VENUE_LOGO, filled($path) ? $path : null);
        if (filled($old) && $old !== $path) {
            Storage::disk(self::DISK)->delete($old);
        }
    }
}
