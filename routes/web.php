<?php

use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\DamagePhotoController;
use App\Http\Controllers\EventFileController;
use App\Http\Controllers\GuestListPrintController;
use App\Http\Controllers\StagePlanController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

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
});

// Kalender-Feed: Kalender-Apps rufen ohne Anmeldung ab, deshalb mit Schlüssel
// statt hinter dem Panel-Login (siehe CalendarFeedController). Gedrosselt je IP,
// damit niemand den Schlüssel durchprobiert.
Route::get('/kalender/events.ics', CalendarFeedController::class)->middleware('throttle:30,1')->name('calendar.feed');
