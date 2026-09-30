<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\GuestsRelationManager;
use App\Models\Event;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class GuestListTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->event = Event::create(['title' => 'Gala', 'starts_at' => now()->addWeek()]);
    }

    public function test_gaeste_anlegen_aendern_loeschen(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $manager = fn () => Livewire::test(GuestsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class]);

        $manager()->callTableAction('create', data: ['first_name' => 'Erika', 'last_name' => 'Muster', 'free_tickets' => 2])
            ->assertHasNoTableActionErrors();
        $manager()->callTableAction('create', data: ['first_name' => 'Max', 'last_name' => 'Beispiel', 'free_tickets' => 3])
            ->assertHasNoTableActionErrors();

        $erika = $this->event->guests()->where('last_name', 'Muster')->firstOrFail();
        $this->assertSame($admin->id, $erika->created_by);
        $this->assertSame(5, (int) $this->event->guests()->sum('free_tickets'));

        $manager()->callTableAction('edit', $erika, data: ['free_tickets' => 4])->assertHasNoTableActionErrors();
        $this->assertSame(4, $erika->fresh()->free_tickets);

        $manager()->callTableAction('delete', $erika);
        $this->assertSame(1, $this->event->guests()->count());
    }

    public function test_name_und_vorname_sind_pflicht(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(GuestsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class])
            ->callTableAction('create', data: ['first_name' => '', 'last_name' => '', 'free_tickets' => 1])
            ->assertHasTableActionErrors(['first_name' => 'required', 'last_name' => 'required']);
    }

    public function test_leserolle_sieht_die_liste_aber_aendert_nichts(): void
    {
        $this->event->guests()->create(['first_name' => 'Erika', 'last_name' => 'Muster', 'free_tickets' => 2]);
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));

        Livewire::test(GuestsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => ViewEvent::class])
            ->assertCanSeeTableRecords($this->event->guests)
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $this->event->guests->first());
    }

    public function test_druckansicht_sortiert_mit_summe(): void
    {
        $this->event->guests()->createMany([
            ['first_name' => 'Max', 'last_name' => 'Zander', 'free_tickets' => 2],
            ['first_name' => 'Erika', 'last_name' => 'Albers', 'free_tickets' => 3],
        ]);
        $this->actingAs($this->userWith($this->role('einlass', ['events' => 'read'])));

        Livewire::test(GuestsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => ViewEvent::class])
            ->assertTableActionVisible('print');

        $this->get("/events/{$this->event->id}/gaesteliste")
            ->assertOk()
            ->assertSeeInOrder(['Gala', 'Albers', 'Erika', 'Zander', 'Max', 'Gesamt Tickets', '5']);
    }

    public function test_druckansicht_nur_mit_recht_und_anmeldung(): void
    {
        $this->get("/events/{$this->event->id}/gaesteliste")->assertRedirect();

        $this->actingAs($this->userWith($this->role('buchhaltung', ['buchhaltung' => 'read'])));
        $this->get("/events/{$this->event->id}/gaesteliste")->assertForbidden();
    }
}
