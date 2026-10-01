<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\FieldOption;
use App\Models\Promoter;
use App\Models\User;
use App\Support\EventNumber;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class EventWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_nummern_wie_in_der_php_version(): void
    {
        $this->assertSame('00721', EventNumber::formatNr(721));
        $this->assertSame('123456', EventNumber::formatNr('123456'), 'längere Nummern nicht abschneiden');
        $this->assertSame('2005000721', EventNumber::buildVaId('20050', 721));
        $this->assertSame('00721', EventNumber::buildVaId(null, 721), 'ohne Kundennummer nur die laufende Nummer');
    }

    public function test_neues_event_bekommt_naechste_nummer_und_va_id(): void
    {
        $promoter = Promoter::create(['name' => 'Agentur', 'customer_no' => '20050']);
        Event::create(['title' => 'Alt', 'va_nr' => '720', 'starts_at' => now()]);
        $this->actingAs($this->admin());

        Livewire::test(CreateEvent::class)
            ->fillForm(['title' => 'Neu', 'promoter_id' => $promoter->id, 'starts_at' => now()->addMonth()->format('Y-m-d H:i')])
            ->call('create')
            ->assertHasNoFormErrors();

        $event = Event::where('title', 'Neu')->firstOrFail();
        $this->assertSame('00721', $event->va_nr);
        $this->assertSame('2005000721', $event->va_id);
    }

    public function test_veranstalterwechsel_bildet_va_id_neu_nummer_bleibt(): void
    {
        $alt = Promoter::create(['name' => 'Alt', 'customer_no' => '11111']);
        $neu = Promoter::create(['name' => 'Neu', 'customer_no' => '22222']);
        $event = Event::create(['title' => 'Konzert', 'promoter_id' => $alt->id, 'starts_at' => now()]);
        $nr = $event->va_nr;

        $event->update(['promoter_id' => $neu->id]);

        $this->assertSame($nr, $event->fresh()->va_nr);
        $this->assertSame('22222' . $nr, $event->fresh()->va_id);
    }

    public function test_bearbeiten_speichert_kern_und_zusatzdaten(): void
    {
        FieldOption::create(['field_key' => 'accounting_status', 'value' => '1. Rate gezahlt']);
        FieldOption::create(['field_key' => 'accounting_status', 'value' => '2. Rate gezahlt']);
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
        $this->actingAs($this->admin());

        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm([
                'title' => 'Konzert (verlegt)',
                'schedule.admission' => '18:00',
                'finance.accounting_status' => ['1. Rate gezahlt', '2. Rate gezahlt'],
                'finance.rent' => '9500',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $event->refresh();
        $this->assertSame('Konzert (verlegt)', $event->title);
        $this->assertStringStartsWith('18:00', (string) $event->schedule->admission);
        $this->assertSame(['1. Rate gezahlt', '2. Rate gezahlt'], $event->finance->accounting_status);
        $this->assertSame('9500.00', (string) $event->finance->rent);
    }

    public function test_finanzen_nur_mit_schreibrecht_auf_die_buchhaltung(): void
    {
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
        $event->finance()->create(['rent' => 1000]);
        $planer = $this->userWith($this->role('eventmanager', ['events' => 'edit', 'buchhaltung' => 'read']));
        $this->actingAs($planer);

        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm(['finance.rent' => '1'])
            ->call('save');

        $this->assertSame('1000.00', (string) $event->finance()->first()->rent);
    }

    public function test_leserolle_landet_in_der_ansicht(): void
    {
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
        $leser = $this->userWith($this->role('catering', ['events' => 'read']));

        $this->actingAs($leser)->get('/events/' . $event->id . '/edit')->assertRedirect('/events/' . $event->id);
        $this->actingAs($leser)->get('/events/' . $event->id)->assertOk();

        // Ohne Leserecht bleibt es bei 403
        $this->actingAs($this->userWith($this->role('extern')))->get('/events/' . $event->id . '/edit')->assertForbidden();
    }

    public function test_herabgestufter_bearbeiter_speichert_nicht_weiter(): void
    {
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
        $role = $this->role('eventmanager', ['events' => 'edit']);
        $user = $this->userWith($role);
        $this->actingAs($user);
        $page = Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->set('data.title', 'Gekapert');

        // Recht entzogen, während der Bearbeiten-Tab noch offen ist
        $role->update(['permissions' => ['events' => 'read']]);
        $this->actingAs(User::query()->findOrFail($user->id));

        $page->call('save')->assertForbidden();
        $this->assertSame('Konzert', $event->fresh()->title);
    }

    public function test_reiter_haben_kurze_kennungen_in_der_adresse(): void
    {
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
        $this->actingAs($this->userWith($this->role('eventmanager', ['events' => 'edit', 'events_operations' => 'edit'])));

        // Beim Klicken schreibt Filament den Schlüssel des Reiters in die Adresse –
        // er muss der Kennung entsprechen, nicht einem Slug der Beschriftung.
        $this->get('/events/' . $event->id . '/edit?phase=durchfuehrung&bereich=schaeden')
            ->assertOk()
            ->assertSee('data-tab-key="durchfuehrung"', false)
            ->assertSee('data-tab-key="schaeden"', false)
            ->assertDontSee('::tab"', false)
            ->assertDontSee('span-classvc-step', false);
    }
}
