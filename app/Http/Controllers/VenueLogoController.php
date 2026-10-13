<?php

namespace App\Http\Controllers;

use App\Support\VenueLogo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Logo der Halle für angemeldete Benutzer, etwa als Vorschau auf der Seite Halle. Nur PNG und JPG, am Inhalt erkannt. */
class VenueLogoController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $path = VenueLogo::path();
        abort_if($path === null, 404);

        return Storage::disk(VenueLogo::DISK)->response($path, basename($path), [
            'Content-Type' => (string) VenueLogo::mimeType($path),
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
