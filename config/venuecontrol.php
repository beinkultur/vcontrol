<?php

return [

    /*
    | config/config.php der PHP-Version (vc.bein.ws). Aus ihr liest
    | `php artisan vc:import` die Zugangsdaten der Quelldatenbank, damit
    | das Passwort nicht ein zweites Mal in dieser .env stehen muss.
    */

    'legacy_config' => env('VC_LEGACY_CONFIG'),

];
