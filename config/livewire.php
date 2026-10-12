<?php

use App\Support\Uploads;

// Nur was von den Vorgaben abweicht – den Rest liefert Livewire selbst
// (vendor/livewire/livewire/config/livewire.php). Achtung: Livewire mischt nur
// die oberste Ebene, deshalb steht temporary_file_upload hier vollständig.
return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        // Livewire legt jede ausgewählte Datei sofort in die Zwischenablage (livewire-tmp).
        // Was hier nicht durchgeht – Endung keines Upload-Felds, Programm oder Webseite
        // als Inhalt, mehr als 25 MB –, landet gar nicht erst auf dem Server
        // (App\Support\Uploads; die Regel „upload_inhalt“ steht im AppServiceProvider).
        'rules' => ['required', 'file', 'max:' . Uploads::MAX_KB, 'extensions:' . implode(',', Uploads::extensions()), 'upload_inhalt'],
        'directory' => null,
        'middleware' => null,
        // Vorschau aus der Zwischenablage nur für die Bildtypen, die hochgeladen werden dürfen
        'preview_mimes' => ['png', 'gif', 'jpg', 'jpeg', 'webp'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
