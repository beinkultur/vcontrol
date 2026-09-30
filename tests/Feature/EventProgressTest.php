<?php

namespace Tests\Feature;

use App\Enums\AssignmentRole;
use App\Filament\Resources\Events\EventResource;
use App\Models\Employee;
use App\Models\Event;
use App\Models\Promoter;
use App\Support\EventProgress;
use Tests\TestCase;

class EventProgressTest extends TestCase
{
    public function test_fortschritt_wie_in_der_php_version(): void
    {
        $event = Event::create(['title' => 'Leer', 'starts_at' => now()->addMonth()]);
        // Buchung: Titel und Datum sind 2 von 12 Prüfungen, sonst nichts
        $this->assertSame(['buchung' => 17, 'planung' => 0, 'durchfuehrung' => 0], EventProgress::phases($event->fresh()));

        $promoter = Promoter::create(['name' => 'Agentur']);
        $lead = Employee::create(['first_name' => 'Anna', 'last_name' => 'Leitung']);
        $event->update([
            'promoter_id' => $promoter->id, 'event_type1' => 'Konzert', 'event_type2' => 'Pop / Rock',
            'pax_expected' => 3000, 'seating' => ['teilbestuhlt'], 'areas' => ['Innenraum'], 'pax' => 2800, 'closed' => true,
        ]);
        $event->assignments()->create(['role' => AssignmentRole::ProjectLead, 'assignee_type' => 'employee', 'assignee_id' => $lead->id]);
        $event->finance()->create(['contract_status' => 'Vertrag zurück', 'rent' => 9000]);
        $event->pr()->create(['pr_status' => 'angekündigt']);
        $event->schedule()->create(['admission' => '18:00:00', 'end_time' => '23:00:00', 'load_in' => '08:00:00']);
        $event->checklist()->create(['hands' => 'yes', 'cleaning' => 'na', 'chairs_ordered' => 'no']);
        $event->stage()->create(['width' => 14, 'depth' => 8, 'height' => 1.4]);

        $phases = EventProgress::phases($event->fresh());
        $this->assertSame(100, $phases['buchung']);
        // Planung: alles außer Personal (Rolle außer PL), Gewerke und WLAN/Briefing = 8 von 11
        $this->assertSame(73, $phases['planung']);
        // Durchführung: PAX und abgeschlossen = 2 von 5
        $this->assertSame(40, $phases['durchfuehrung']);
    }

    public function test_workspace_zeigt_uebersicht_mit_phasen(): void
    {
        $event = Event::create(['title' => 'Sommerfest', 'starts_at' => now()->addMonth()]);
        $event->schedule()->create(['admission' => '17:30:00', 'start_time' => '19:00:00']);

        $this->actingAs($this->admin())
            ->get(EventResource::getUrl('edit', ['record' => $event]))
            ->assertOk()
            ->assertSeeInOrder(['Sommerfest', 'Übersicht', 'Buchung', 'Planung', 'Durchführung'])
            ->assertSee('Beginn 19:00')
            ->assertSee('Einlass 17:30')
            ->assertSee('Planungsbereiche')
            ->assertSee('?phase=planung&amp;bereich=zeiten', escape: false)
            ->assertSee('Notizen');
    }
}
