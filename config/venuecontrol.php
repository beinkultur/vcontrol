<?php

return [

    /*
    | Abgeschaltete Module (Bereichs-Schlüssel aus App\Access\Area), gesperrt für
    | alle; gespeicherte Rechte bleiben erhalten. Nur die Vorgabe: Der System-Admin
    | schaltet Module unter Verwaltung › System – ab dem ersten Speichern dort gilt
    | diese Einstellung nicht mehr (App\Access\Modules).
    */

    'disabled_areas' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VC_DISABLED_AREAS', 'schichten,admin_schichtplanung,schichten_extern')),
    ))),

    /*
    | config/config.php der PHP-Version (vc.bein.ws). Aus ihr liest
    | `php artisan vc:import` die Zugangsdaten der Quelldatenbank, damit
    | das Passwort nicht ein zweites Mal in dieser .env stehen muss.
    */

    'legacy_config' => env('VC_LEGACY_CONFIG'),

    /*
    | Sperrt `php artisan vc:import`. Ab dem Go-live setzen: Dann kommen die
    | Daten aus AppSheet, ein Import aus der PHP-Version würde alles in Laravel
    | Eingegebene überschreiben (auch Passwörter und gesperrte Konten).
    */

    'import_locked' => (bool) env('VC_IMPORT_LOCKED', false),

    /*
    | Sollwerte des Smoke-Tests der PHP-Version (Statuscode je Rolle und Seite).
    | Standard: scripts/smoke_baseline.json neben der legacy_config.
    */

    'legacy_baseline' => env('VC_LEGACY_BASELINE'),

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

];
