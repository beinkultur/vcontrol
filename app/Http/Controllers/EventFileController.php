<?php

namespace App\Http\Controllers;

use App\Access\Area;
use App\Models\Event;
use App\Models\EventFile;
use App\Models\User;
use App\Support\Involvement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Herunterladen einer Event-Datei. Die Dateien liegen nicht öffentlich; wer das
 * Event sehen darf, bekommt sie – übergreifende Dateien jeder mit Leserecht auf
 * Events, wie in der PHP-Version (FileDownload). Externe (Freelancer, Gewerke)
 * bekommen die nicht verborgenen Dateien der Events, an denen sie beteiligt sind.
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
        abort_unless(self::canDownload($file), 403);

        return self::stream($file);
    }

    /** Auch für den Daysheet-Link (DaysheetController), der seine Rechte selbst prüft. */
    public static function stream(EventFile $file): StreamedResponse
    {
        $disk = Storage::disk(EventFile::DISK);
        abort_unless($disk->exists($file->path), 404);

        $type = $file->inlineType();
        $headers = ['Content-Type' => $type ?? 'application/octet-stream'];
        if ($type !== 'application/pdf') {
            $headers['Content-Security-Policy'] = self::SANDBOX;
        }

        return $disk->response($file->path, $file->original_name, $headers, $type !== null ? 'inline' : 'attachment');
    }

    private static function canDownload(EventFile $file): bool
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            return false;
        }

        $internal = $file->event !== null
            ? Gate::forUser($user)->allows('view', $file->event)
            : $file->is_shared && $user->access()->can(Area::Events);
        if ($internal) {
            return true;
        }

        if ($file->hidden_from_externals || !$user->access()->canUseExtern()) {
            return false;
        }
        $events = $file->event_id !== null
            ? Event::query()->whereKey($file->event_id)
            : Event::query()->whereIn('id', $file->linkedEvents()->pluck('events.id'));

        return Involvement::scope($events, $user)->exists();
    }
}
