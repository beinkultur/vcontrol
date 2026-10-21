<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Promoter;
use App\Support\MeterReadings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „Abrechnung abgeschlossen lässt sich nicht zurücksetzen“ (gemeldet am
 * 21.10.2026): Mit echten IPA-Daten scheiterte das Speichern an Prüfungen in
 * anderen Reitern – archivierter Veranstalter, nicht aufsteigende Alt-Zählerstände.
 */
class EventSaveErrorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($this->admin());
    }

    public function test_archivierter_veranstalter_bleibt_gueltig(): void
    {
        $promoter = Promoter::create(['name' => 'Alte Agentur', 'is_archived' => true]);
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->subMonth(), 'promoter_id' => $promoter->id]);
        $event->finance()->create(['accounting_closed' => true]);

        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm(['finance.accounting_closed' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($event->finance()->value('accounting_closed'));
        $this->assertSame($promoter->id, $event->fresh()->promoter_id);
    }

    public function test_unveraenderte_alt_zaehlerstaende_blockieren_nicht(): void
    {
        $meter = Meter::create(['name' => 'Strom', 'unit' => 'kWh', 'per_event' => true]);
        $first = Event::create(['title' => 'Erstes', 'starts_at' => now()->subMonths(2)]);
        MeterReadings::saveForEvent($first, $meter, MeterReading::START, 100);
        MeterReadings::saveForEvent($first, $meter, MeterReading::END, 500);
        // Altdaten: später, aber kleiner als der vorige Stand, dazu Ende unter Anfang
        $event = Event::create(['title' => 'Zweites', 'starts_at' => now()->subMonth()]);
        MeterReadings::saveForEvent($event, $meter, MeterReading::START, 300);
        MeterReadings::saveForEvent($event, $meter, MeterReading::END, 250);
        $event->finance()->create(['accounting_closed' => true]);

        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm(['finance.accounting_closed' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse($event->finance()->value('accounting_closed'));

        // Wer den Stand ändert, bekommt die Prüfung – und eine Meldung mit Reiter und Feld
        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm(["meter_{$meter->id}_start" => 200, "meter_{$meter->id}_end" => 600])
            ->call('save')
            ->assertHasFormErrors(["meter_{$meter->id}_start"])
            ->assertNotified('Nicht gespeichert');
    }

    public function test_meldung_nennt_reiter_und_feld(): void
    {
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);

        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm(['title' => ''])
            ->call('save')
            ->assertHasFormErrors(['title' => 'required']);

        $notification = collect(session('filament.claimed_notifications'))->last();
        $this->assertSame('Nicht gespeichert', $notification['title'] ?? null);
        $this->assertStringContainsString('Buchung › Daten › Titel:', (string) ($notification['body'] ?? ''));
    }
}
