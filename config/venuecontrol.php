<?php

return [

    /*
    | Abgeschaltete Bereiche (Schlüssel aus App\Access\Area), gesperrt für alle;
    | gespeicherte Rechte bleiben erhalten. Nur die Vorgabe: Der System-Admin
    | schaltet Bereiche (und gibt Module frei) unter Verwaltung › System – ab dem
    | ersten Speichern dort gilt diese Einstellung nicht mehr (App\Access\Modules).
    */

    'disabled_areas' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VC_DISABLED_AREAS', 'schichten,admin_schichtplanung,schichten_extern')),
    ))),

    /*
    | config/config.php der PHP-Version (vc.bein.ws), Quelle von `php artisan
    | vc:import`. Die PHP-Version ist seit 12.10.2026 gelöscht (Archiv in der
    | Nextcloud, Code auf GitHub beinkultur/VenueControl) – vc:import hat damit
    | keine Quelle mehr und ist gesperrt.
    */

    'legacy_config' => env('VC_LEGACY_CONFIG'),

    /*
    | Sperrt `php artisan vc:import`. Die Daten kommen zum Go-live aus
    | AppSheet; ein Import würde alles in Laravel Eingegebene überschreiben
    | (auch Passwörter und gesperrte Konten).
    */

    'import_locked' => (bool) env('VC_IMPORT_LOCKED', true),

    /*
    | Sollwerte für `php artisan vc:check-access`: Statuscode je Rolle und Seite
    | aus dem Smoke-Test der PHP-Version, beim Löschen der PHP-Version ins Repo
    | übernommen.
    */

    'legacy_baseline' => env('VC_LEGACY_BASELINE', base_path('scripts/php-smoke-baseline.json')),

    /*
    | Nächtliche Sicherung (App\Support\Backups): Link einer Datei-Ablage
    | („nur Hochladen“, mit Passwort) in der Nextcloud des Betreibers, etwa
    | https://nx.bein.ws/s/…. Der Webspace kann dort nur abliefern, nichts lesen
    | oder löschen. Ohne beide Werte ist die Sicherung aus.
    */

    'backup' => [
        'share_url' => env('VC_BACKUP_SHARE_URL'),
        'share_password' => env('VC_BACKUP_SHARE_PASSWORD'),
    ],

    /*
    | Statusroute /_ops/status für das Cockpit (ops.bein.ws): Version,
    | Datenbankgröße, offene Migrationen, Stand der Sicherung. Ohne Token gibt
    | es die Route nicht (404).
    */

    'ops_status_token' => env('OPS_STATUS_TOKEN'),

    /*
    | Website von VenueControl: verlinkt in „erstellt mit VenueControl“
    | (Daysheet-Mail und Daysheet-Seite).
    */

    'website' => 'https://www.vcontrol.eu',

];
