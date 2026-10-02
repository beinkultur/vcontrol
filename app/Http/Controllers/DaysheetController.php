<?php

namespace App\Http\Controllers;

use App\Models\Daysheet;
use App\Models\Event;
use App\Models\EventFile;
use App\Models\Setting;
use App\Support\ExternSheet;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Daysheet-Seite: Wer den Link aus der Mail hat, sieht ohne Konto die Event-Infos
 * für Externe (App\Support\ExternSheet), bis zum Ende des Tages nach der
 * Veranstaltung oder bis jemand den Link sperrt – und kann sie drucken oder als
 * PDF speichern. Dazu die Vorschau für das Team, ohne Link.
 */
class DaysheetController extends Controller
{
    /** Der Link enthält den Schlüssel: nicht indexieren, nicht weitergeben, nicht zwischenspeichern. */
    private const HEADERS = [
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'no-store, private',
    ];

    public function show(string $token): Response
    {
        $daysheet = Daysheet::findByToken($token);
        abort_if($daysheet === null, 404);

        if (!$daysheet->isValid()) {
            return response()
                ->view('daysheet-expired', ['revoked' => $daysheet->isRevoked(), 'venue' => self::venue()], 410)
                ->withHeaders(self::HEADERS);
        }

        return response()
            ->view('daysheet', [
                'sheet' => ExternSheet::make($daysheet->event, fn (EventFile $file): string => route('daysheet.file', [$token, $file])),
                'validUntil' => $daysheet->expires_at,
                'preview' => false,
                'documentTitle' => self::documentTitle($daysheet->event),
                'venue' => self::venue(),
            ])
            ->withHeaders(self::HEADERS);
    }

    public function file(string $token, EventFile $file): StreamedResponse
    {
        $daysheet = Daysheet::findByToken($token);
        abort_unless($daysheet !== null && $daysheet->isValid() && ExternSheet::showsFile($daysheet->event, $file), 404);

        $response = EventFileController::stream($file);
        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /** So sieht das Daysheet für die Empfänger aus – für alle, die das Event sehen dürfen. */
    public function preview(Event $event): Response
    {
        Gate::authorize('view', $event);

        return response()->view('daysheet', [
            'sheet' => ExternSheet::make($event, fn (EventFile $file): string => $file->downloadUrl()),
            'validUntil' => null,
            'preview' => true,
            'documentTitle' => self::documentTitle($event),
            'venue' => self::venue(),
        ]);
    }

    private static function documentTitle(Event $event): string
    {
        return ($event->starts_at ?? now())->format('Ymd') . ' ' . trim((string) $event->title) . ' – Daysheet';
    }

    private static function venue(): string
    {
        return Setting::lookup(Setting::VENUE_NAME) ?: (string) config('app.name');
    }
}
