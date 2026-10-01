<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Support\StagePlan;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Bühnenplan zum Drucken (A4 quer) und als SVG-Datei, wie in der PHP-Version. */
class StagePlanController extends Controller
{
    public function print(Event $event): View
    {
        Gate::authorize('view', $event);

        return view('stage-plan-print', [
            'event' => $event,
            'svg' => StagePlan::toSvg(StagePlan::build($event->stage, $event)),
            'documentTitle' => StagePlan::documentTitle($event),
        ]);
    }

    public function svg(Event $event): Response
    {
        Gate::authorize('view', $event);

        // Texte sind escaped (StagePlan); die Sandbox verhindert Skripte trotzdem,
        // falls doch einmal etwas durchrutscht – SVG läuft sonst vom App-Ursprung.
        return response('<?xml version="1.0" encoding="UTF-8"?>' . StagePlan::toSvg(StagePlan::build($event->stage, $event)), 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Content-Disposition' => 'inline; filename="buehnenplan-' . $event->id . '.svg"',
            'Content-Security-Policy' => EventFileController::SANDBOX,
        ]);
    }
}
