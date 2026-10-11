<?php

namespace App\Support;

use App\Mail\BackupFailed;
use App\Models\BackupRun;
use App\Models\EventFile;
use App\Models\Setting;
use App\Models\User;
use Closure;
use FilesystemIterator;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Nächtliche Sicherung in die Nextcloud des Betreibers: jede Nacht die
 * Datenbank (DatabaseDump), sonntags – und wenn die letzte älter als eine Woche
 * ist – dazu die Dateien (Event-Dateien, Schadensfotos) als ZIP.
 *
 * Ziel ist eine Datei-Ablage („nur Hochladen“, mit Passwort, config
 * venuecontrol.backup): Der Webspace kann dort nur abliefern, nichts lesen oder
 * löschen und kommt an nichts anderes in der Nextcloud. Alte Sicherungen räumt
 * deshalb nicht VenueControl weg. Auslöser ist ein KAS-Cronjob, der die Adresse
 * mit dem Schlüssel aus Setting::BACKUP_CRON_KEY aufruft (BackupCronController);
 * schlägt dabei etwas fehl, bekommen die System-Admins eine Mail.
 */
final class Backups
{
    /** Ab wann die letzte Datenbank-Sicherung als ausgeblieben gilt */
    private const STALE_HOURS = 36;

    /** Läufe so lange aufheben (nur die Liste, nicht die Dateien) */
    private const KEEP_RUNS_DAYS = 180;

    /** @return array{base: string, token: string}|null aus dem Link https://host/s/TOKEN (auch …/index.php/s/TOKEN) */
    public static function target(): ?array
    {
        $url = trim((string) config('venuecontrol.backup.share_url'));

        return preg_match('~^(https://.+?)(?:/index\.php)?/s/([A-Za-z0-9]+)/?$~', $url, $match) === 1
            ? ['base' => $match[1], 'token' => $match[2]]
            : null;
    }

    public static function isConfigured(): bool
    {
        return self::target() !== null && filled(config('venuecontrol.backup.share_password'));
    }

    /** Wohin gesichert wird – ohne den Schlüssel des Links. */
    public static function targetLabel(): string
    {
        $target = self::target();
        if ($target === null) {
            return 'Nicht eingerichtet: In der .env fehlt VC_BACKUP_SHARE_URL (Link der Datei-Ablage, https://…/s/…).';
        }
        if (!filled(config('venuecontrol.backup.share_password'))) {
            return 'Nicht eingerichtet: In der .env fehlt VC_BACKUP_SHARE_PASSWORD (Passwort des Links).';
        }

        return 'Datei-Ablage auf ' . parse_url($target['base'], PHP_URL_HOST);
    }

    /**
     * Sichert die Datenbank und, wenn fällig oder verlangt, die Dateien.
     *
     * @param  string  $trigger  zeitplan | manuell | konsole
     * @return Collection<int, BackupRun>|null  null: Es läuft schon eine Sicherung.
     */
    public static function run(string $trigger, ?bool $withFiles = null): ?Collection
    {
        @set_time_limit(900);
        $lock = Cache::lock('vc-sicherung', 1800);
        if (!$lock->get()) {
            return null;
        }

        try {
            $withFiles ??= self::filesDue();
            $runs = collect([self::attempt(BackupRun::DATABASE, $trigger, self::databaseFile(...))]);
            if ($withFiles) {
                $runs->push(self::attempt(BackupRun::FILES, $trigger, self::filesFile(...)));
            }
            self::prune();

            $failed = $runs->where('status', BackupRun::FAILED);
            // Wer von Hand sichert, sieht das Ergebnis sofort
            if ($failed->isNotEmpty() && $trigger !== 'manuell') {
                self::notify($failed);
            }

            return $runs;
        } finally {
            $lock->release();
        }
    }

    /** Dateien sonntags, und immer, wenn die letzte gelungene älter als eine Woche ist (erster Lauf, verpasster Sonntag). */
    public static function filesDue(): bool
    {
        return now()->isSunday()
            || !BackupRun::query()
                ->where('kind', BackupRun::FILES)
                ->where('status', BackupRun::OK)
                ->where('started_at', '>=', now()->subDays(7))
                ->exists();
    }

    public static function lastOk(string $kind): ?BackupRun
    {
        return BackupRun::query()->where('kind', $kind)->where('status', BackupRun::OK)->latest('started_at')->latest('id')->first();
    }

    /** Hinweis für System-Admins: eingerichtet, aber die letzte Datenbank-Sicherung fehlt, scheiterte oder ist zu alt. */
    public static function warning(): ?string
    {
        if (!self::isConfigured()) {
            return null;
        }

        $last = BackupRun::query()
            ->where('kind', BackupRun::DATABASE)
            ->whereIn('status', [BackupRun::OK, BackupRun::FAILED])
            ->latest('started_at')
            ->latest('id')
            ->first();

        return match (true) {
            $last === null => 'Es gibt noch keine Sicherung – einmal „Jetzt sichern“ und den KAS-Cronjob einrichten.',
            $last->status === BackupRun::FAILED => 'Die letzte Sicherung (' . $last->started_at?->format('d.m.Y H:i') . ' Uhr) ist fehlgeschlagen: ' . $last->error,
            $last->started_at === null || $last->started_at->lt(now()->subHours(self::STALE_HOURS)) => 'Die letzte Sicherung ist vom ' . $last->started_at?->format('d.m.Y H:i')
                . ' Uhr – der nächtliche Aufruf durch den KAS-Cronjob bleibt offenbar aus.',
            default => null,
        };
    }

    public static function cronUrl(): ?string
    {
        $key = Setting::lookup(Setting::BACKUP_CRON_KEY);

        return filled($key) ? route('backup.cron', ['key' => $key]) : null;
    }

    public static function newCronKey(): void
    {
        Setting::put(Setting::BACKUP_CRON_KEY, Str::random(40));
    }

    public static function keyMatches(mixed $key): bool
    {
        $stored = (string) Setting::lookup(Setting::BACKUP_CRON_KEY);

        return $stored !== '' && is_string($key) && hash_equals($stored, $key);
    }

    /** @param  Closure(string): string  $build  legt die Datei im übergebenen Ordner an und gibt den Pfad zurück */
    private static function attempt(string $kind, string $trigger, Closure $build): BackupRun
    {
        // Nicht ins Änderungsprotokoll – siehe BackupRun
        $run = Audit::quietly(fn (): BackupRun => BackupRun::query()->create([
            'kind' => $kind,
            'status' => BackupRun::RUNNING,
            'trigger' => $trigger,
            'started_at' => now(),
        ]));
        $path = null;

        try {
            if (!self::isConfigured()) {
                throw new RuntimeException(self::targetLabel());
            }
            $path = $build(self::workDir());
            $size = (int) filesize($path);
            self::upload($path);
            Audit::quietly(fn () => $run->update([
                'status' => BackupRun::OK,
                'file_name' => basename($path),
                'size_bytes' => $size,
                'finished_at' => now(),
            ]));
        } catch (Throwable $e) {
            $error = self::withoutSecrets($e->getMessage());
            Log::warning('Sicherung fehlgeschlagen', ['art' => $kind, 'fehler' => $error]);
            Audit::quietly(fn () => $run->update([
                'status' => BackupRun::FAILED,
                'error' => Str::limit($error, 1000),
                'finished_at' => now(),
            ]));
        } finally {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }

        return $run;
    }

    private static function databaseFile(string $dir): string
    {
        $path = $dir . '/' . self::fileName('datenbank', 'sql.gz');
        DatabaseDump::toFile($path);

        return $path;
    }

    /** storage/app/private ohne Livewire-Zwischenablage, dazu eine kurze Erklärung. */
    private static function filesFile(string $dir): string
    {
        $path = $dir . '/' . self::fileName('dateien', 'zip');
        $root = rtrim(Storage::disk(EventFile::DISK)->path(''), '/');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Das ZIP-Archiv lässt sich nicht anlegen.');
        }

        $count = 0;
        if (is_dir($root)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if ($file->isFile() && !str_starts_with($relative, 'livewire-tmp/')) {
                    $zip->addFile($file->getPathname(), $relative);
                    $count++;
                }
            }
        }
        $zip->addFromString('LIESMICH.txt', sprintf(
            "VenueControl %s, Dateien vom %s: %d Dateien aus storage/app/private (Event-Dateien, Schadensfotos).\n"
            . "Zurückspielen: Inhalt nach storage/app/private entpacken; die Datenbank-Sicherung derselben Nacht gehört dazu.\n",
            self::host(),
            now()->format('d.m.Y H:i'),
            $count,
        ));
        if (!$zip->close()) {
            throw new RuntimeException('Das ZIP-Archiv lässt sich nicht abschließen.');
        }

        return $path;
    }

    /** HTTP PUT in die Datei-Ablage – WebDAV für öffentliche Links (Nextcloud ab 29), Passwort per Basic-Auth. */
    private static function upload(string $path): void
    {
        $target = self::target() ?? throw new RuntimeException(self::targetLabel());
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Die Sicherungsdatei lässt sich nicht lesen.');
        }

        try {
            $response = Http::withBasicAuth('anonymous', (string) config('venuecontrol.backup.share_password'))
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->connectTimeout(20)
                ->timeout(600)
                ->withBody(Utils::streamFor($handle), 'application/octet-stream')
                ->put($target['base'] . '/public.php/dav/files/' . $target['token'] . '/' . rawurlencode(basename($path)));
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (!$response->successful()) {
            throw new RuntimeException('Die Nextcloud antwortet ' . $response->status() . match ($response->status()) {
                401 => ' – stimmt das Passwort des Links?',
                403 => ' – der Link erlaubt kein Hochladen (in der Nextcloud „Datei-Ablage“ wählen), oder die Datei gibt es dort schon.',
                404 => ' – den Link gibt es nicht mehr.',
                507 => ' – der Speicher der Nextcloud ist voll.',
                default => '.',
            });
        }
    }

    /** Mail an die System-Admins, wenn ein Lauf aus dem Zeitplan scheitert. @param  Collection<int, BackupRun>  $failed */
    private static function notify(Collection $failed): void
    {
        $recipients = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->where('is_system_admin', true))
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new BackupFailed($failed->values()->all(), self::host()));
            } catch (Throwable $e) {
                Log::warning('Meldung über fehlgeschlagene Sicherung nicht versendet', ['an' => $email, 'fehler' => $e->getMessage()]);
            }
        }
    }

    /** Alte Einträge weg, hängengebliebene Läufe (Zeitlimit des Webservers) als gescheitert markieren. */
    private static function prune(): void
    {
        BackupRun::query()->where('started_at', '<', now()->subDays(self::KEEP_RUNS_DAYS))->delete();
        BackupRun::query()
            ->where('status', BackupRun::RUNNING)
            ->where('started_at', '<', now()->subHour())
            ->update(['status' => BackupRun::FAILED, 'error' => 'Abgebrochen (Zeitlimit oder Absturz).', 'finished_at' => now()]);
    }

    /** Arbeitsordner außerhalb von public/ und von storage/app/private (sonst im eigenen ZIP). */
    private static function workDir(): string
    {
        $dir = storage_path('app/backup-tmp');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Der Arbeitsordner für die Sicherung lässt sich nicht anlegen.');
        }

        return $dir;
    }

    /** z. B. ipa.vcontrol.eu-datenbank-2026-10-12-033000.sql.gz – eindeutig je Halle und Lauf */
    private static function fileName(string $kind, string $extension): string
    {
        return self::host() . '-' . $kind . '-' . now()->format('Y-m-d-His') . '.' . $extension;
    }

    private static function host(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'venuecontrol');
    }

    /** Fehlermeldungen (Log, Liste, Mail) ohne Schlüssel des Links und Passwort. */
    private static function withoutSecrets(string $message): string
    {
        $secrets = array_filter([self::target()['token'] ?? null, (string) config('venuecontrol.backup.share_password')]);

        return $secrets === [] ? $message : str_replace($secrets, '…', $message);
    }
}
