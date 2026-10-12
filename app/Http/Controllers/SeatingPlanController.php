<?php

namespace App\Http\Controllers;

use App\Access\Area;
use App\Models\Event;
use App\Models\SeatingPlan;
use App\Models\User;
use App\Support\Involvement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Saalpläne (PDF) ausliefern. Sie liegen nicht öffentlich. Ein Standard-Saalplan
 * für alle, die Events sehen oder die Pläne pflegen; der Saalplan eines Events
 * (eigener oder Standard) für alle, die das Event sehen, und für Externe, die
 * daran beteiligt sind – wie die Event-Dateien. Den Daysheet-Link prüft der
 * DaysheetController selbst.
 *
 * Angezeigt wird nur, was am Inhalt als PDF erkannt ist; alles andere kommt als
 * Download in einer Sandbox (EventFileController::SANDBOX).
 */
class SeatingPlanController extends Controller
{
    public function standard(SeatingPlan $plan): StreamedResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && ($user->access()->can(Area::Events) || $user->access()->can(Area::AdminStammdaten)), 403);

        return self::file($plan->path, $plan->original_name ?: $plan->name . '.pdf', $plan->mime_type);
    }

    public function event(Event $event): StreamedResponse
    {
        abort_unless(self::canSee($event), 403);

        return self::stream($event);
    }

    /** Eigener Saalplan des Events, sonst sein Standard-Saalplan. */
    public static function stream(Event $event): StreamedResponse
    {
        if ($event->hasCustomSeatingPlan()) {
            return self::file((string) $event->seating_plan_path, $event->seating_plan_original_name ?: 'Saalplan.pdf', $event->seating_plan_mime_type);
        }

        $plan = $event->seatingPlan;
        abort_if($plan === null, 404);

        return self::file($plan->path, $plan->original_name ?: $plan->name . '.pdf', $plan->mime_type);
    }

    private static function file(string $path, string $name, ?string $mimeType): StreamedResponse
    {
        $disk = Storage::disk(SeatingPlan::DISK);
        abort_unless($disk->exists($path), 404);

        $pdf = SeatingPlan::detectedPdf($mimeType);
        $headers = ['Content-Type' => $pdf ? 'application/pdf' : 'application/octet-stream'];
        if (!$pdf) {
            $headers['Content-Security-Policy'] = EventFileController::SANDBOX;
        }

        return $disk->response($path, $name, $headers, $pdf ? 'inline' : 'attachment');
    }

    private static function canSee(Event $event): bool
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            return false;
        }
        if (Gate::forUser($user)->allows('view', $event)) {
            return true;
        }

        return $user->access()->canUseExtern()
            && Involvement::scope(Event::query()->whereKey($event->getKey()), $user)->exists();
    }
}
