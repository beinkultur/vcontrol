<?php

use App\Http\Controllers\GuestListPrintController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

// Die Oberfläche liefert das Filament-Panel „app“ direkt unter / aus
// (App\Providers\Filament\AppPanelProvider). Hierher gehören nur Routen
// außerhalb des Panels, etwa der ICS-Feed.

// Seiten ohne Panel-Rahmen (Druckansichten), aber mit dessen Anmeldung.
$panel = Filament::getPanel('app');
Route::middleware([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()])->group(function (): void {
    Route::get('/events/{event}/gaesteliste', GuestListPrintController::class)->name('events.guest-list-print');
});
