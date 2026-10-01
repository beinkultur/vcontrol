<?php

return [

    /*
    | Vorübergehend abgeschaltete Module (Bereichs-Schlüssel aus App\Access\Area).
    | Gesperrt für alle, auch für Admins; gespeicherte Rechte bleiben erhalten.
    | Je Halle über VC_DISABLED_AREAS einstellbar.
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
    | Sollwerte des Smoke-Tests der PHP-Version (Statuscode je Rolle und Seite).
    | Standard: scripts/smoke_baseline.json neben der legacy_config.
    */

    'legacy_baseline' => env('VC_LEGACY_BASELINE'),


    /*
    | Empfänger für neue Schadensmeldungen (mit Komma getrennt). Leer: alle
    | aktiven Benutzer mit der Rolle „hausmeister“ – wie in der PHP-Version.
    */
    'damage_notify' => env('VC_DAMAGE_NOTIFY'),

];
