<?php

use App\Http\Controllers\BackupCronController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\DamagePhotoController;
use App\Http\Controllers\DaysheetController;
use App\Http\Controllers\EventFileController;
use App\Http\Controllers\GuestListPrintController;
use App\Http\Controllers\OpsStatusController;
use App\Http\Controllers\SeatingPlanController;
use App\Http\Controllers\StagePlanController;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Die Oberfläche liefert das Filament-Panel „app“ direkt unter / aus
// (App\Providers\Filament\AppPanelProvider). Hierher gehören nur Routen
// außerhalb des Panels, etwa der ICS-Feed.

// Seiten ohne Panel-Rahmen (Druckansichten), aber mit dessen Anmeldung.
$panel = Filament::getPanel('app');
Route::middleware([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()])->group(function (): void {
    Route::get('/events/{event}/gaesteliste', GuestListPrintController::class)->name('events.guest-list-print');
    Route::get('/events/{event}/buehnenplan/druck', [StagePlanController::class, 'print'])->name('events.stage-plan-print');
    Route::get('/events/{event}/buehnenplan.svg', [StagePlanController::class, 'svg'])->name('events.stage-plan-svg');
    Route::get('/dateien/{file}/download', EventFileController::class)->name('event-files.download');
    Route::get('/schaeden/{damage}/foto/{index}', DamagePhotoController::class)->whereNumber('index')->name('damages.photo');
    Route::get('/events/{event}/daysheet', [DaysheetController::class, 'preview'])->name('events.daysheet-preview');
    Route::get('/events/{event}/saalplan', [SeatingPlanController::class, 'event'])->name('events.seating-plan');
    Route::get('/saalplaene/{plan}/pdf', [SeatingPlanController::class, 'standard'])->name('seating-plans.pdf');
});

// Daysheet: ohne Konto, der Schlüssel im Link berechtigt (bis zum Tag nach der
// Veranstaltung, sperrbar).
// Gedrosselt je IP, damit niemand Schlüssel durchprobiert.
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('/daysheet/{token}', [DaysheetController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{48}')
        ->name('daysheet.show');
    Route::get('/daysheet/{token}/dateien/{file}', [DaysheetController::class, 'file'])
        ->where('token', '[A-Za-z0-9]{48}')
        ->name('daysheet.file');
    Route::get('/daysheet/{token}/saalplan', [DaysheetController::class, 'seatingPlan'])
        ->where('token', '[A-Za-z0-9]{48}')
        ->name('daysheet.seating-plan');
});

// Kalender-Feed: Kalender-Apps rufen ohne Anmeldung ab, deshalb mit Schlüssel
// statt hinter dem Panel-Login (siehe CalendarFeedController). Gedrosselt je IP,
// damit niemand den Schlüssel durchprobiert.
Route::get('/kalender/events.ics', CalendarFeedController::class)->middleware('throttle:30,1')->name('calendar.feed');

// Statusauskunft für das Cockpit (ops.bein.ws), nur mit Token im Header X-Ops-Token
Route::get('/_ops/status', OpsStatusController::class)
    ->middleware('throttle:30,1')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->name('ops.status');

// Nächtliche Sicherung: ruft der KAS-Cronjob mit Schlüssel auf (App\Support\Backups).
// Ohne Sitzung – sonst stünde jede Nacht eine Zeile in `sessions`; damit auch
// ohne CSRF-Schutz, der für sein Cookie die Sitzung braucht (nur GET, kein Formular).
Route::get('/cron/sicherung', BackupCronController::class)
    ->middleware('throttle:10,1')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->name('backup.cron');
