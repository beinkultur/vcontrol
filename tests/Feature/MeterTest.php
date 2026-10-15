<?php

namespace Tests\Feature;

use App\Filament\Pages\Meters;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Meters\Pages\ManageMeters;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Support\AuditPresenter;
use App\Support\MeterReadings;
use App\Support\MeterStats;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Zählerstände: Zähler der Venue, Stände je Event und frei, Auswertung, Rechte. */
class MeterTest extends TestCase
{
    private Meter $water;

    private Meter $power;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->travelTo(Carbon::parse('2026-10-15 12:00'));
        // Den Stromzähler je Event legt die Migration an
        $this->power = Meter::query()->where('name', 'Strom')->sole();
        $this->water = Meter::create(['name' => 'Wasser', 'unit' => 'm³', 'sort_order' => 20]);
    }

    private function read(Meter $meter, string $at, float $value, bool $restart = false): MeterReading
    {
        return $meter->readings()->create(['read_at' => $at, 'value' => $value, 'restart' => $restart]);
    }

    public function test_migration_legt_den_stromzaehler_je_event_an(): void
    {
        $this->assertSame('kWh', $this->power->unit);
        $this->assertTrue($this->power->per_event);
        $this->assertSame(['Strom'], Meter::forEvents()->pluck('name')->all());
    }

    public function test_verbrauch_verteilt_sich_auf_die_monate(): void
    {
        // 590 m³ vom 1.1. bis 1.3.: 31 Tage Januar, 28 Tage Februar
        $this->read($this->water, '2026-01-01 00:00:00', 1000);
        $this->read($this->water, '2026-03-01 00:00:00', 1590);
        // Zählerwechsel: der Sprung auf den neuen Zähler zählt nicht
        $this->read($this->water, '2026-03-15 00:00:00', 1730);
        $swap = $this->read($this->water, '2026-03-15 00:05:00', 2, restart: true);
        $this->read($this->water, '2026-04-01 00:00:00', 172);
        // Rücksprung (Tippfehler?): zählt nicht
        $typo = $this->read($this->water, '2026-04-10 00:00:00', 100);

        $stats = MeterStats::for($this->water);
        $months = $stats->months(2026);
        $this->assertEqualsWithDelta(310.0, $months[1], 0.001);
        $this->assertEqualsWithDelta(280.0, $months[2], 0.001);
        $this->assertEqualsWithDelta(140.0 + 170.0, $months[3], 0.001);
        // Ohne Verbrauch dazwischen: leer, nicht 0
        $this->assertNull($months[4]);
        $this->assertNull($months[5]);
        $this->assertNull($stats->months(2025)[12]);

        $this->assertNull($stats->delta($swap));
        $this->assertEqualsWithDelta(-72.0, $stats->delta($typo), 0.001);
        $this->assertSame([2026], $stats->years());

        $year = $stats->year(2026);
        $this->assertEqualsWithDelta(900.0, $year['amount'], 0.001);
        $this->assertSame('2026-04-10', $year['until']->toDateString());
        $this->assertNull($year['previous']);
    }

    public function test_jahr_gegen_dieselben_tage_im_vorjahr(): void
    {
        // 2025: 10 m³ am Tag; 2026: 11 m³ am Tag, aber nur bis 2. März abgelesen
        $this->read($this->water, '2025-01-01 00:00:00', 0);
        $this->read($this->water, '2025-12-31 00:00:00', 3640);
        $this->read($this->water, '2026-01-01 00:00:00', 3650);
        $this->read($this->water, '2026-03-02 00:00:00', 4310);

        $stats = MeterStats::for($this->water);
        $year = $stats->year(2026);
        // Verglichen mit denselben 60 Tagen 2025, nicht mit dem ganzen Jahr
        $this->assertEqualsWithDelta(660.0, $year['amount'], 0.001);
        $this->assertEqualsWithDelta(600.0, $year['previous'], 0.001);
        $this->assertEqualsWithDelta(11.0, $year['perDay'], 0.001);
        $this->assertSame('+10 %', MeterStats::change($year['amount'], $year['previous']));
        $this->assertSame('−10 %', MeterStats::change(90, 100));
        $this->assertNull(MeterStats::change(90, null));

        // März nur bis zum 2. abgelesen: angebrochen, April ohne Stände leer
        $this->assertSame('bis 02.03.', $stats->partialMonth(2026, 3));
        $this->assertNull($stats->partialMonth(2026, 2));
        $this->assertEqualsWithDelta(11.0, $stats->months(2026)[3], 0.001);
        $this->assertNull($stats->months(2026)[4]);
    }

    public function test_je_event_mit_pax_und_neuem_datum(): void
    {
        $event = Event::create(['title' => 'Rock im Herbst', 'starts_at' => '2026-09-12 20:00:00', 'pax' => 2000]);
        MeterReadings::saveForEvent($event, $this->power, MeterReading::START, 1000);
        MeterReadings::saveForEvent($event, $this->power, MeterReading::END, 4000);
        Event::create(['title' => 'Ohne Ende', 'starts_at' => '2026-09-20 20:00:00'])->meterReadings()
            ->create(['meter_id' => $this->power->id, 'position' => MeterReading::START, 'read_at' => '2026-09-20 00:00:00', 'value' => 4100]);

        $rows = MeterStats::for($this->power)->events(2026);
        $this->assertCount(1, $rows);
        $this->assertSame(3000.0, $rows[0]['amount']);
        $this->assertSame(1.5, $rows[0]['perPax']);
        $this->assertSame([], MeterStats::for($this->power)->events(2025));

        // Neue Tage: die Stände ziehen mit, Ende am Abend des letzten Tags
        $event->update(['starts_at' => '2026-09-05 20:00:00', 'ends_at' => '2026-09-06 23:00:00']);
        $this->assertSame(['2026-09-05 00:00:00', '2026-09-06 23:59:59'], $event->meterReadings()->orderBy('read_at')->get()
            ->map(fn (MeterReading $reading): string => $reading->read_at->format('Y-m-d H:i:s'))->all());
    }

    public function test_formate(): void
    {
        $this->assertSame('485.033 kWh', MeterStats::reading('485033.000', 'kWh'));
        $this->assertSame('18.452,317 m³', MeterStats::reading(18452.317, 'm³'));
        $this->assertSame('0 m³', MeterStats::reading(0, 'm³'));
        $this->assertSame('2.683 kWh', MeterStats::amount(2683.4, 'kWh'));
        $this->assertSame('48,5 m³', MeterStats::amount(48.46, 'm³'));
        $this->assertSame('1,25 MWh', MeterStats::amount(1.25, 'MWh'));
        $this->assertSame('0 kWh', MeterStats::amount(0.0, 'kWh'));
        $this->assertSame('–', MeterStats::amount(null, 'kWh'));
        $this->assertSame('12500.5', MeterReadings::plain('12500.500'));
    }

    public function test_seite_nach_recht(): void
    {
        $this->read($this->water, '2026-09-01 07:30:00', 18450.5);
        $this->read($this->water, '2026-10-01 07:30:00', 18902.25);

        $this->actingAs($this->userWith($this->role('zaehler-lesen', ['zaehler' => 'read'])));
        $this->get('/zaehler?zaehler=' . $this->water->id)->assertOk()
            ->assertSee('Wasser')
            ->assertSee('18.902,25 m³')
            ->assertSee('Verbrauch je Monat in m³')
            ->assertDontSee('Stand erfassen')
            ->assertDontSee('Zähler verwalten');
        $this->get('/zaehler?zaehler=' . $this->water->id . '&jahr=2025')->assertOk()->assertSee('Keine Stände 2025');
        $this->get('/zaehler/verwaltung')->assertForbidden();

        $this->actingAs($this->userWith($this->role('nur-events', ['events' => 'read'])));
        $this->get('/zaehler')->assertForbidden();

        $this->actingAs($this->userWith($this->role('technik', ['zaehler' => 'edit'])));
        $this->get('/zaehler')->assertOk()->assertSee('Stand erfassen')->assertSee('Verbrauch je Event in kWh');

        $this->actingAs($this->admin());
        $this->get('/zaehler/verwaltung')->assertOk()->assertSee('Wasser')->assertSee('Strom');
    }

    public function test_stand_erfassen_wird_gegen_die_nachbarn_geprueft(): void
    {
        $this->read($this->water, '2026-09-01 07:30:00', 1000);
        $this->read($this->water, '2026-10-01 07:30:00', 1400);
        $this->actingAs($this->userWith($this->role('technik', ['zaehler' => 'edit'])));
        $page = fn () => Livewire::withQueryParams(['zaehler' => (string) $this->water->id])->test(Meters::class);

        // Kleiner als der Stand davor, größer als der danach: abgelehnt
        $page()->callAction('createReading', data: ['meter_id' => $this->water->id, 'read_at' => '2026-09-15 08:00', 'value' => '900'])
            ->assertHasActionErrors(['value']);
        $page()->callAction('createReading', data: ['meter_id' => $this->water->id, 'read_at' => '2026-09-15 08:00', 'value' => '1500'])
            ->assertHasActionErrors(['value']);
        $this->assertSame(2, $this->water->readings()->count());

        $page()->callAction('createReading', data: ['meter_id' => $this->water->id, 'read_at' => '2026-09-15 08:00', 'value' => '1180.25', 'note' => 'Rundgang'])
            ->assertHasNoActionErrors();
        // Neuer Zähler: kleiner geht mit Zählerwechsel
        $page()->callAction('createReading', data: ['meter_id' => $this->water->id, 'read_at' => '2026-10-10 09:00', 'value' => '3', 'restart' => true])
            ->assertHasNoActionErrors();

        $created = $this->water->readings()->where('note', 'Rundgang')->sole();
        $this->assertSame('1180.250', $created->value);
        $this->assertNotNull($created->created_by);
        $this->assertTrue($this->water->readings()->where('value', 3)->sole()->restart);

        // Ändern: wieder gegen die Nachbarn, ohne sich selbst
        $page()->callTableAction('edit', $created, data: ['value' => '1390'])->assertHasNoTableActionErrors();
        $this->assertSame('1390.000', $created->fresh()->value);
        $page()->callTableAction('edit', $created, data: ['value' => '1401'])->assertHasTableActionErrors(['value']);
        $page()->callTableAction('delete', $created);
        $this->assertModelMissing($created);
    }

    public function test_event_staende_nur_im_event(): void
    {
        $event = Event::create(['title' => 'Rock im Herbst', 'starts_at' => '2026-10-03 20:00:00']);
        MeterReadings::saveForEvent($event, $this->power, MeterReading::START, 1000);
        $reading = $event->meterReadings()->sole();

        $this->actingAs($this->userWith($this->role('technik', ['zaehler' => 'edit'])));
        Livewire::withQueryParams(['zaehler' => (string) $this->power->id])->test(Meters::class)
            ->assertCanSeeTableRecords([$reading])
            ->assertTableActionHidden('edit', $reading)
            ->assertTableActionHidden('delete', $reading);

        $this->actingAs($this->userWith($this->role('lesen', ['zaehler' => 'read'])));
        Livewire::test(Meters::class)->assertActionHidden('createReading');
    }

    public function test_event_formular_prueft_gegen_die_uebrigen_staende(): void
    {
        $this->actingAs($this->admin());
        // Monatsablesung am 1.10. bei 5.000 kWh – ein Event am 3.10. kann nicht bei 4.000 beginnen
        $this->read($this->power, '2026-10-01 07:30:00', 5000);
        $event = Event::create(['title' => 'Rock im Herbst', 'starts_at' => '2026-10-03 20:00:00']);
        $form = fn () => Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()]);

        $form()->fillForm(["meter_{$this->power->id}_start" => '4000', "meter_{$this->power->id}_end" => '4500'])
            ->call('save')
            ->assertHasFormErrors(["meter_{$this->power->id}_start"]);
        $form()->fillForm(["meter_{$this->power->id}_start" => '5100', "meter_{$this->power->id}_end" => '6300'])
            ->assertSee('1.200 kWh')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['5100.000', '6300.000'], $event->meterReadings()->orderBy('read_at')->pluck('value')->all());

        // Eigene Stände zählen beim erneuten Speichern nicht als Nachbarn
        $form()->fillForm(["meter_{$this->power->id}_end" => '6200'])->call('save')->assertHasNoFormErrors();

        // Ohne aktive Zähler „je Event“ kein Abschnitt
        $this->power->update(['is_active' => false]);
        $form()->assertDontSee('Zählerstände');
    }

    public function test_zaehler_verwalten(): void
    {
        $this->actingAs($this->userWith($this->role('technik', ['zaehler' => 'edit'])));
        $this->get('/zaehler/verwaltung')->assertForbidden();

        $this->actingAs($this->admin());
        Livewire::test(ManageMeters::class)
            ->callAction('create', data: ['name' => 'Fernwärme', 'unit' => 'MWh', 'per_event' => false, 'is_active' => true])
            ->assertHasNoActionErrors();
        $heat = Meter::query()->where('name', 'Fernwärme')->sole();
        $this->assertGreaterThan($this->water->sort_order, $heat->sort_order);

        // Löschen nur ohne Stände
        $this->read($this->water, '2026-10-01 07:30:00', 1);
        Livewire::test(ManageMeters::class)
            ->assertTableActionHidden('delete', $this->water)
            ->callTableAction('delete', $heat);
        $this->assertModelMissing($heat);
    }

    public function test_protokoll(): void
    {
        $this->actingAs($this->admin());
        $reading = $this->read($this->water, '2026-10-01 07:30:00', 18902.25);

        $log = AuditLog::query()->where('subject', 'meter_readings')->sole();
        $this->assertSame('Wasser · 01.10.2026', $log->subject_label);
        $this->assertSame('Zählerstand', AuditPresenter::subject('meter_readings'));
        $this->assertSame('Stand', AuditPresenter::field('meter_readings', 'value'));
        $this->assertSame('Wasser', AuditPresenter::value('meter_id', $this->water->id));
        $this->assertSame('Ende', AuditPresenter::value('position', 'end'));
        $this->assertSame((string) $reading->id, $log->subject_key);
    }
}
