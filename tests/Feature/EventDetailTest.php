<?php

namespace Tests\Feature;

use App\Models\Event;
use Tests\TestCase;

class EventDetailTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = Event::create(['title' => 'Sommerfest', 'status' => 'bestätigt', 'starts_at' => now()->addWeek()]);
        $this->event->schedule()->create(['admission' => '17:00:00', 'start_time' => '18:30:00']);
        $this->event->finance()->create([
            'accounting_status' => ['1. Rate gezahlt', '2. Rate gezahlt'],
            'invoice_numbers' => ['1037', '1085'],
            'rent' => 11500,
        ]);
    }

    public function test_zeiten_und_finanzen_fuer_die_buchhaltung(): void
    {
        $buchhaltung = $this->userWith($this->role('buchhaltung', ['events' => 'read', 'buchhaltung' => 'read']));

        $this->actingAs($buchhaltung)->get('/events/' . $this->event->id)
            ->assertOk()
            ->assertSee('17:00')
            ->assertSee('18:30')
            ->assertSee('2. Rate gezahlt')
            ->assertSee('1085');
    }

    public function test_finanzen_nur_mit_recht_auf_die_buchhaltung(): void
    {
        $catering = $this->userWith($this->role('catering', ['events' => 'read']));

        $this->actingAs($catering)->get('/events/' . $this->event->id)
            ->assertOk()
            ->assertSee('17:00')
            ->assertDontSee('FIBU-Status')
            ->assertDontSee('1085');
    }

    public function test_wlan_passwort_nur_fuer_bearbeiter(): void
    {
        $this->event->update(['wlan' => 'Arena-Gast', 'wlan_password' => 'geheim-4711']);
        $catering = $this->userWith($this->role('catering', ['events' => 'read']));

        $this->actingAs($catering)->get('/events/' . $this->event->id)
            ->assertOk()
            ->assertSee('Arena-Gast')
            ->assertDontSee('geheim-4711');

        $this->actingAs($this->admin())->get('/events/' . $this->event->id . '/edit')
            ->assertOk()
            ->assertSee('geheim-4711');
    }

    public function test_fibu_status_als_liste_durchsuchbar(): void
    {
        $this->assertSame(1, Event::whereHas('finance', fn ($q) => $q->whereJsonContains('accounting_status', '2. Rate gezahlt'))->count());
        $this->assertSame(0, Event::whereHas('finance', fn ($q) => $q->whereJsonContains('accounting_status', '2. Rate'))->count());
    }
}
