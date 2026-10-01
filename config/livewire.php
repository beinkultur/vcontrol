<?php

// Nur was von den Vorgaben abweicht – den Rest liefert Livewire selbst
// (vendor/livewire/livewire/config/livewire.php). Achtung: Livewire mischt nur
// die oberste Ebene, deshalb steht temporary_file_upload hier vollständig.
return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        // Event-Dateien bis 15 MB wie in der PHP-Version (Livewire-Vorgabe: 12 MB)
        'rules' => ['required', 'file', 'max:15360'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
