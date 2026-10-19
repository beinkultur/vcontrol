<?php

namespace App\Http\Controllers;

use App\Access\Module;
use App\Models\Event;
use App\Models\Setting;
use App\Support\GuestLists;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Gästeliste für den Einlass, wie in der PHP-Version – nur mit dem Modul
 * Gästeliste: zum Drucken aus dem Browser (A4 hoch) und seit 19.10.2026 als PDF,
 * das der Server immer in DIN A4 setzt (GuestLists::pdf).
 */
class GuestListPrintController extends Controller
{
    public function pdf(Event $event): Response
    {
        abort_unless(Module::GuestList->isEnabled(), 404);
        Gate::authorize('view', $event);

        return response(GuestLists::pdf($event), 200, [
            'Content-Type' => 'application/pdf',
            // „Gästeliste …“ mit Umlaut: dazu eine ASCII-Fassung für ältere Programme
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', GuestLists::fileName($event), Str::ascii(GuestLists::fileName($event))),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function __invoke(Event $event): View
    {
        abort_unless(Module::GuestList->isEnabled(), 404);
        Gate::authorize('view', $event);

        $guests = GuestLists::guests($event);

        return view('guest-list-print', [
            'event' => $event->load('promoter'),
            'guests' => $guests,
            'ticketTotal' => (int) $guests->sum('free_tickets'),
            'venueName' => Setting::lookup(Setting::VENUE_NAME),
        ]);
    }
}
