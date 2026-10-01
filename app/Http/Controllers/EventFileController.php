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
 *
 * Im Browser angezeigt werden nur PDFs, Bilder und Text mit festem Typ
 * (EventFile::inlineType()), alles andere wird heruntergeladen. Außer bei PDFs
 * (Chrome zeigt sie in einer Sandbox nicht an) läuft die Antwort in einer
 * Sandbox ohne Skripte.
 */
class EventFileController extends Controller
{
    public const SANDBOX = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox";

    public function __invoke(EventFile $file): StreamedResponse
    {
        $user = Auth::user();
        $allowed = $file->event !== null
            ? Gate::allows('view', $file->event)
            : $file->is_shared && $user instanceof User && $user->access()->can(Area::Events);
        abort_unless($allowed, 403);

        $disk = Storage::disk(EventFile::DISK);
        abort_unless($disk->exists($file->path), 404);

        $type = $file->inlineType();
        $headers = ['Content-Type' => $type ?? 'application/octet-stream'];
        if ($type !== 'application/pdf') {
            $headers['Content-Security-Policy'] = self::SANDBOX;
        }

        return $disk->response($file->path, $file->original_name, $headers, $type !== null ? 'inline' : 'attachment');
    }
}
