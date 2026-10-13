<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use App\Models\User;
use App\Support\PersonalIcsFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * /kalender/persoenlich.ics: der persönliche Kalender eines Kontos (eigene
 * Dienste) für Kalender-Apps – mit dem Schlüssel aus dem Profil, ohne Schlüssel
 * für das angemeldete Konto selbst. Nur aktive Konten, die „Persönlich“ lesen
 * dürfen (Recht „Kalender“ oder „Kalender (extern)“); ein falscher Schlüssel
 * öffnet nichts, auch nicht für Angemeldete.
 */
class PersonalCalendarFeedController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->query->has('token') ? User::forFeedToken($request->query('token')) : Auth::user();
        if (!$user instanceof User || !$user->is_active || !$user->access()->canCalendar(Calendar::PERSONAL)) {
            return response("Kein Zugriff.\n", 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response(PersonalIcsFeed::build($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="persoenlich.ics"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
