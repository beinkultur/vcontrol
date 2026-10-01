<?php

namespace App\Http\Controllers;

use App\Models\Damage;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Foto eines Schadens; sehen darf es, wer Schäden sehen darf (DamagePolicy::viewAny). */
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
        abort_unless(is_string($path) && $disk->exists($path), 404);

        return $disk->response($path, $damage->photo_names[$path] ?? basename($path), [], 'inline');
    }
}
