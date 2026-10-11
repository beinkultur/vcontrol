<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Daysheet;
use App\Models\EventFile;
use App\Models\Role;
use App\Models\User;
use FilesystemIterator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sicherheitslage innerhalb von VenueControl, für das Cockpit (Statusroute
 * /_ops/status, dort täglich geprüft und im Wochenbericht) und die Seite
 * Verwaltung › System: Befunde mit Schweregrad wie im Cockpit (hoch heißt dort
 * sofortige Warnmail) und Zahlen ohne Bewertung.
 *
 * Nur lesend; die Grundlage ist das Änderungsprotokoll der letzten 7 Tage.
 */
final class SecurityStatus
{
    /** Zeitraum für „neu“ und „geändert“ */
    private const DAYS = 7;

    /** Aktive Konten ohne Anmeldung seit so vielen Tagen gelten als ungenutzt */
    private const INACTIVE_DAYS = 90;

    /** Seit diesem Tag wird die letzte Anmeldung erfasst – davor heißt „leer“ nur „unbekannt“. */
    public const LOGINS_SINCE = '2026-10-12';

    /** Livewire legt Uploads hier ab und räumt sie erst beim nächsten Upload weg */
    private const UPLOAD_TMP = 'livewire-tmp';

    private const EXECUTABLES = ['exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'ps1', 'sh', 'php', 'phtml', 'phar', 'js', 'jar', 'vbs', 'dmg', 'app', 'apk'];

    /** @return list<array{check: string, severity: string, title: string}> */
    public static function findings(): array
    {
        $findings = [];
        $add = function (string $check, string $severity, string $title) use (&$findings): void {
            $findings[] = ['check' => $check, 'severity' => $severity, 'title' => $title];
        };

        if (!Backups::isConfigured()) {
            $add('sicherung', 'high', 'Keine Sicherung eingerichtet: '.Backups::targetLabel());
        } elseif (($warning = Backups::warning()) !== null) {
            $add('sicherung', 'high', 'Sicherung: '.$warning);
        }

        foreach (self::newFullAccess() as $line) {
            $add('vollzugriff', 'high', $line);
        }

        $roles = self::changes('roles')->pluck('subject_label')->filter()->unique()->values();
        if ($roles->isNotEmpty()) {
            $add('rechte', 'medium', 'Rechte geändert an: '.$roles->implode(', ').' – vom System-Admin so gewollt?');
        }

        $inactive = self::inactiveAccounts();
        if ($inactive !== []) {
            $add('ungenutzt', 'low', count($inactive).' aktive(s) Konto/Konten seit über '.self::INACTIVE_DAYS.' Tagen nicht angemeldet: '
                .self::names($inactive).' – deaktivieren?');
        }

        if (app()->isProduction() && !config('venuecontrol.import_locked')) {
            $add('import', 'low', 'vc:import ist nicht gesperrt – spätestens zum Go-live VC_IMPORT_LOCKED=true setzen.');
        }

        [$leftovers, $executables] = self::uploadLeftovers();
        if ($executables !== []) {
            $add('uploads', 'medium', 'Ausführbare Dateien in der Upload-Zwischenablage: '.self::names($executables).' – wer lädt so etwas hoch?');
        } elseif ($leftovers > 0) {
            $add('uploads', 'low', "{$leftovers} liegengebliebene Datei(en) in der Upload-Zwischenablage (älter als 24 Stunden).");
        }

        return $findings;
    }

    /** @return list<string> Zahlen und Namen für den Wochenbericht, ohne Bewertung */
    public static function facts(): array
    {
        $fullAccess = User::query()->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->where(fn ($role) => $role->where('is_super', true)->orWhere('is_system_admin', true)))
            ->with('roles')
            ->get()
            ->map(fn (User $user): string => $user->getFilamentName().($user->access()->isSystemAdmin() ? ' (System-Admin)' : ''))
            ->sort()
            ->values()
            ->all();
        $active = User::query()->where('is_active', true)->count();
        $recent = User::query()->where('is_active', true)->where('last_login_at', '>=', now()->subDays(self::DAYS))->count();
        $users = self::changes('users');
        $count = fn (string $action): int => $users->where('action', $action)->count();

        return [
            'Vollzugriff: '.($fullAccess === [] ? 'niemand' : implode(', ', $fullAccess)),
            "Angemeldet in den letzten 7 Tagen: {$recent} von {$active} aktiven Konten",
            sprintf(
                'Letzte 7 Tage: %d Konto/Konten angelegt, %d gelöscht, %d geändert, %d Rollen-Zuordnung(en)',
                $count(Audit::CREATED),
                $count(Audit::DELETED),
                $count(Audit::UPDATED),
                self::changes('role_user')->count(),
            ),
            'Gültige Daysheet-Links: '.Daysheet::query()->get()->filter(fn (Daysheet $daysheet): bool => $daysheet->isValid())->count(),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, AuditLog> */
    private static function changes(string $subject)
    {
        return AuditLog::query()
            ->where('subject', $subject)
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->orderBy('id')
            ->get();
    }

    /** Admin- oder System-Admin-Rolle in den letzten 7 Tagen vergeben (Schlüssel „Benutzer:Rolle“, Audit::key). @return list<string> */
    private static function newFullAccess(): array
    {
        $fullRoles = Role::query()->where(fn ($role) => $role->where('is_super', true)->orWhere('is_system_admin', true))->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

        return self::changes('role_user')
            ->where('action', Audit::CREATED)
            ->filter(fn (AuditLog $log): bool => in_array((string) (explode(':', (string) $log->subject_key)[1] ?? ''), $fullRoles, true))
            ->map(fn (AuditLog $log): string => 'Vollzugriff vergeben: '.($log->subject_label ?? '?').' am '.$log->created_at?->format('d.m. H:i').' durch '.($log->user_name ?? '?'))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private static function inactiveAccounts(): array
    {
        $limit = now()->subDays(self::INACTIVE_DAYS);
        // „Nie angemeldet“ zählt erst, wenn die Erfassung lange genug läuft
        $neverCounts = now()->gte(Carbon::parse(self::LOGINS_SINCE)->addDays(self::INACTIVE_DAYS));

        return User::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('last_login_at', '<', $limit)
                ->when($neverCounts, fn ($query) => $query->orWhere(fn ($never) => $never->whereNull('last_login_at')->where('created_at', '<', $limit))))
            ->orderBy('last_name')
            ->get()
            ->map(fn (User $user): string => $user->getFilamentName())
            ->all();
    }

    /** @return array{int, list<string>} Anzahl liegengebliebener Dateien, darunter ausführbare (Originalname) */
    private static function uploadLeftovers(): array
    {
        $dir = Storage::disk(EventFile::DISK)->path(self::UPLOAD_TMP);
        if (!is_dir($dir)) {
            return [0, []];
        }

        $count = 0;
        $executables = [];
        try {
            foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $file) {
                if (!$file->isFile() || str_ends_with($file->getFilename(), '.json') || $file->getMTime() > now()->subDay()->getTimestamp()) {
                    continue;
                }
                $count++;
                // Livewire legt den Originalnamen in einer .json daneben ab
                $meta = json_decode((string) @file_get_contents($file->getPathname().'.json'), true);
                $name = is_array($meta) && is_string($meta['name'] ?? null) ? $meta['name'] : $file->getFilename();
                if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXECUTABLES, true)) {
                    $executables[] = $name.' ('.date('d.m.', $file->getMTime()).')';
                }
            }
        } catch (Throwable) {
            return [0, []];
        }

        return [$count, $executables];
    }

    /** @param  list<string>  $names */
    private static function names(array $names): string
    {
        return implode(', ', array_slice($names, 0, 8)).(count($names) > 8 ? ' und '.(count($names) - 8).' weitere' : '');
    }
}
