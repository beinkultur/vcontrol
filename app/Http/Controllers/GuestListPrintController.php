<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Setting;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Gästeliste zum Drucken für den Einlass, wie in der PHP-Version (A4 hoch). */
class GuestListPrintController extends Controller
{
    public function __invoke(Event $event): View
    {
        Gate::authorize('view', $event);

        $guests = $event->guests()->orderBy('last_name')->orderBy('first_name')->orderBy('id')->get();

        return view('guest-list-print', [
            'event' => $event->load('promoter'),
            'guests' => $guests,
            'ticketTotal' => (int) $guests->sum('free_tickets'),
            'venueName' => Setting::lookup(Setting::VENUE_NAME),
        ]);
    }
}
