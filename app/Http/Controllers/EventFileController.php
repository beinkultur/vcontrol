<?php

namespace App\Http\Controllers;

use App\Access\Area;
use App\Models\EventFile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Herunterladen einer Event-Datei. Die Dateien liegen nicht öffentlich; wer das
 * Event sehen darf, bekommt sie – übergreifende Dateien jeder mit Leserecht auf
 * Events, wie in der PHP-Version (FileDownload).
 */
class EventFileController extends Controller
{
    public function __invoke(EventFile $file): StreamedResponse
    {
        $user = Auth::user();
        $allowed = $file->event !== null
            ? Gate::allows('view', $file->event)
            : $file->is_shared && $user instanceof User && $user->access()->can(Area::Events);
        abort_unless($allowed, 403);

        $disk = Storage::disk(EventFile::DISK);
        abort_unless($disk->exists($file->path), 404);

        return $disk->response(
            $file->path,
            $file->original_name,
            ['Content-Type' => $file->mime_type ?: 'application/octet-stream'],
            $file->opensInline() ? 'inline' : 'attachment',
        );
    }
}
