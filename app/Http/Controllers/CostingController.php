<?php

namespace App\Http\Controllers;

use App\Access\Module;
use App\Models\Costing;
use App\Models\Event;
use App\Models\User;
use App\Support\CostingPdf;
use App\Support\CostingSettings;
use App\Support\Involvement;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Costing als PDF – nur mit dem Modul Preislisten & Costing. Jede Fassung für
 * alle, die Costings sehen (CostingPolicy, Rollen unter Verwaltung › Halle); die
 * zuletzt verschickte eines Events außerdem für beteiligte Externe, wenn die
 * Halle es für die Ansicht für Externe freigibt. Den Daysheet-Link prüft der
 * DaysheetController selbst.
 */
class CostingController extends Controller
{
    public function pdf(Costing $costing): Response
    {
        abort_unless(Module::PriceLists->isEnabled(), 404);
        Gate::authorize('view', $costing);

        return self::pdfResponse($costing);
    }

    /** Zuletzt verschickte Fassung des Events (Ansicht für Externe) */
    public function event(Event $event): Response
    {
        abort_unless(Module::PriceLists->isEnabled(), 404);
        $costing = $event->sentCosting();
        abort_if($costing === null, 404);

        $user = Auth::user();
        abort_unless($user instanceof User && (Gate::forUser($user)->allows('view', $costing) || self::externMaySee($user, $event)), 403);

        return self::pdfResponse($costing);
    }

    public static function pdfResponse(Costing $costing): Response
    {
        return response(CostingPdf::render($costing), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . addcslashes($costing->fileName(), '"\\') . '"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private static function externMaySee(User $user, Event $event): bool
    {
        return CostingSettings::inExtern()
            && $user->access()->canUseExtern()
            && Involvement::scope(Event::query()->whereKey($event->getKey()), $user)->exists();
    }
}
