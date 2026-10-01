<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/** Durchführung › Betrieb: Bus-Strom, Stromzähler, die Ja/Nein-Punkte des Show Days. */
class EventExecutionTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($this->admin());
        $this->event = Event::create(['title' => 'Konzert', 'starts_at' => now()->subDay()]);
    }

    private function form()
    {
        return Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()]);
    }

    public function test_bus_strom_als_anzahl_und_zaehlerstaende(): void
    {
        $this->form()
            ->fillForm([
                'operation.bus_power' => 3,
                'operation.power_meter_start' => '12500.5',
                'operation.power_meter_end' => '12740',
            ])
            ->assertSee('239,50 kWh')
            ->call('save')
            ->assertHasNoFormErrors();

        $operation = $this->event->operation()->firstOrFail();
        $this->assertSame(3, $operation->bus_power);
        $this->assertSame('12500.50', $operation->power_meter_start);
        $this->assertSame(240, $operation->power_consumption); // berechnet, gerundet
    }

    public function test_zaehler_ende_nicht_kleiner_als_anfang(): void
    {
        $this->form()
            ->fillForm(['operation.power_meter_start' => '500', 'operation.power_meter_end' => '400'])
            ->call('save')
            ->assertHasFormErrors(['operation.power_meter_end']);

        $this->form()
            ->fillForm(['operation.bus_power' => 6])
            ->call('save')
            ->assertHasFormErrors(['operation.bus_power']);
    }

    public function test_ja_nein_punkte_wie_in_der_php_version(): void
    {
        // Die Planung hat „entfällt“ gesetzt – bleibt, solange niemand in der Durchführung wählt
        $this->event->checklist()->create(['special_cleaning' => 'na']);

        $this->form()
            ->assertSchemaStateSet(['special_cleaning' => 'na', 'house_delay' => null, 'power_ant' => null])
            ->fillForm(['house_delay' => 1, 'power_ant' => 0, 'house_rig_early' => 1, 'sold_out_award' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->event->refresh();
        $this->assertSame('na', $this->event->checklist->special_cleaning);
        $this->assertTrue($this->event->operation->house_delay);
        $this->assertFalse($this->event->checklist->power_ant);
        $this->assertTrue($this->event->checklist->house_rig_early);
        $this->assertTrue($this->event->stage->sold_out_award);

        $this->form()
            ->fillForm(['special_cleaning' => 'yes'])
            ->call('save');
        $this->assertSame('yes', $this->event->checklist()->value('special_cleaning'));
        $this->assertSame(1, $this->event->checklist()->count());
    }

    public function test_neues_event_ohne_zusatzzeilen(): void
    {
        // Neue Events haben noch keine Zeilen für Betrieb und Checkliste: Abschnitt „Strom“ legt
        // die Betriebszeile an, die Ja/Nein-Punkte ändern sie nur – keine doppelten Zeilen.
        $this->form()
            ->fillForm(['operation.bus_power' => 1, 'house_delay' => 1, 'special_cleaning' => 'no'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, $this->event->operation()->count());
        $this->assertTrue($this->event->operation()->value('house_delay'));
        $this->assertSame(1, $this->event->operation()->value('bus_power'));
        $this->assertSame('no', $this->event->checklist()->value('special_cleaning'));
    }
}
