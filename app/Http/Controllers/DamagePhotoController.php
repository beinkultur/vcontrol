<?php

namespace App\Http\Controllers;

use App\Models\Damage;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto eines Schadens; sehen darf es, wer Schäden sehen darf (DamagePolicy::viewAny).
 * Ausgeliefert werden nur Bilder aus damages/ – auch wenn ein Datensatz auf eine
 * andere Datei der Disk zeigen sollte –, in einer Sandbox ohne Skripte.
 */
class DamagePhotoController extends Controller
{
    public function __invoke(Damage $damage, int $index): StreamedResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && Gate::allows('viewAny', Damage::class), 403);
        if ($damage->event !== null) {
            abort_unless(Gate::allows('view', $damage->event), 403);
        }

        $path = array_values($damage->photos ?? [])[$index] ?? null;
        $disk = Storage::disk(Damage::DISK);
        abort_unless(is_string($path) && str_starts_with($path, Damage::PHOTO_DIRECTORY . '/') && $disk->exists($path), 404);
        $type = $disk->mimeType($path);
        abort_unless(in_array($type, Damage::PHOTO_TYPES, true), 404);

        return $disk->response($path, $damage->photo_names[$path] ?? basename($path), [
            'Content-Type' => $type,
            'Content-Security-Policy' => EventFileController::SANDBOX,
        ], 'inline');
    }
}
