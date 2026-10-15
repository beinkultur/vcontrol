<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\Meter;
use App\Models\MeterReading;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/** Durchführung › Betrieb: Bus-Strom, Zählerstände je Event, die Ja/Nein-Punkte des Show Days. */
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
        // Den Stromzähler je Event legt die Migration an
        $power = Meter::query()->where('name', 'Strom')->sole();
        $this->form()
            ->fillForm([
                'operation.bus_power' => 3,
                "meter_{$power->id}_start" => '12500.5',
                "meter_{$power->id}_end" => '12540',
            ])
            ->assertSee('39,5 kWh')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(3, $this->event->operation()->firstOrFail()->bus_power);
        $readings = $this->event->meterReadings()->orderBy('position', 'desc')->get();
        $this->assertSame(['start', 'end'], $readings->pluck('position')->all());
        $this->assertSame(['12500.500', '12540.000'], $readings->pluck('value')->all());
        // Abgelesen am Morgen und am Abend des Event-Tags
        $this->assertSame($this->event->starts_at->format('Y-m-d') . ' 00:00:00', $readings[0]->read_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->event->starts_at->format('Y-m-d') . ' 23:59:59', $readings[1]->read_at->format('Y-m-d H:i:s'));

        // Wieder im Formular, ohne Nullen am Ende; leer löscht den Stand
        $this->form()
            ->assertSchemaStateSet(["meter_{$power->id}_start" => '12500.5', "meter_{$power->id}_end" => '12540'])
            ->fillForm(["meter_{$power->id}_end" => null])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['start'], $this->event->meterReadings()->pluck('position')->all());
    }

    public function test_zaehler_ende_nicht_kleiner_als_anfang(): void
    {
        $power = Meter::query()->where('name', 'Strom')->sole();
        $this->form()
            ->fillForm(["meter_{$power->id}_start" => '500', "meter_{$power->id}_end" => '400'])
            ->call('save')
            ->assertHasFormErrors(["meter_{$power->id}_end"]);
        $this->assertSame(0, MeterReading::query()->count());

        $this->form()
            ->fillForm(['operation.bus_power' => 6])
            ->call('save')
            ->assertHasFormErrors(['operation.bus_power']);
    }

    public function test_ja_nein_punkte_wie_in_der_php_version(): void
    {
        // Ein altes „entfällt“ gibt es bei der Sonderreinigung nicht mehr: nur ja/nein
        $this->event->checklist()->create(['special_cleaning' => 'na']);

        $this->form()
            ->assertSchemaStateSet(['special_cleaning' => null, 'house_delay' => null, 'power_ant' => null])
            ->fillForm(['house_delay' => 1, 'power_ant' => 0, 'house_rig_early' => 1, 'sold_out_award' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->event->refresh();
        $this->assertNull($this->event->checklist->special_cleaning);
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
        // Neue Events haben noch keine Zeilen für Betrieb und Checkliste: Abschnitt „Bus-Strom“ legt
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
