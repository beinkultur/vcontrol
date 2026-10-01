<?php

namespace App\Http\Controllers;

use App\Access\Area;
use App\Models\User;
use App\Support\EventIcsFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * /kalender/events.ics wie in der PHP-Version: mit Schlüssel für Kalender-Apps,
 * ohne Schlüssel für angemeldete Benutzer mit Recht auf den Kalender.
 */
class CalendarFeedController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = Auth::user();
        $allowed = EventIcsFeed::tokenMatches($request->query('token'))
            || ($user instanceof User && $user->access()->can(Area::Kalender));

        if (!$allowed) {
            return EventIcsFeed::isEnabled()
                ? response("Kein Zugriff.\n", 403, ['Content-Type' => 'text/plain; charset=utf-8'])
                : response("Feed nicht aktiviert.\n", 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response(EventIcsFeed::build(), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="veranstaltungen.ics"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
