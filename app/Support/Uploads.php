<?php

namespace App\Support;

use App\Models\Damage;
use App\Models\EventFile;
use Illuminate\Http\UploadedFile;

/**
 * Was hochgeladen werden darf. Livewire legt jede ausgewählte Datei sofort in
 * seine Zwischenablage (`storage/app/private/livewire-tmp`), lange bevor ein
 * Formular gespeichert wird. Deshalb prüft schon dieser erste Schritt Endung,
 * Inhalt und Größe (`config/livewire.php`) – was dort abgewiesen wird, landet
 * gar nicht erst auf dem Server. Die Felder selbst prüfen beim Speichern noch
 * einmal genauer, etwa Archive nur für Admins.
 */
final class Uploads
{
    /** Höchstgröße je Datei in KB (25 MB) – PHP selbst lässt mehr zu (public/.user.ini). */
    public const MAX_KB = 25 * 1024;

    /** Inhalte, die kein Upload haben darf, egal unter welcher Endung: Webseiten, Skripte, Programme */
    public const BLOCKED_TYPES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml',
        'text/javascript', 'application/javascript', 'text/x-php', 'application/x-httpd-php',
        'text/x-shellscript', 'application/x-sh',
        'application/x-dosexec', 'application/vnd.microsoft.portable-executable', 'application/x-msdownload',
        'application/x-msi', 'application/x-ms-installer', 'application/x-executable', 'application/x-pie-executable',
        'application/x-sharedlib', 'application/x-mach-binary',
    ];

    public const BLOCKED_MESSAGE = 'Webseiten, SVG-Grafiken, Skripte und Programme sind als Datei nicht erlaubt.';

    /** Alle Endungen, die irgendein Upload-Feld annimmt – die Grenze für die Zwischenablage. @return list<string> */
    public static function extensions(): array
    {
        return array_values(array_unique([...EventFile::EXTENSIONS, ...EventFile::ARCHIVE_EXTENSIONS, ...Damage::PHOTO_EXTENSIONS]));
    }

    /** Am erkannten Inhalt, nicht an der Endung: ein Programm als „rechnung.pdf“ fällt hier auf. */
    public static function hasBlockedContent(mixed $file): bool
    {
        return $file instanceof UploadedFile && in_array(strtolower((string) $file->getMimeType()), self::BLOCKED_TYPES, true);
    }
}
