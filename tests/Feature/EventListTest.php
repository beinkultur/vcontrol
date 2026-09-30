<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use App\Models\Promoter;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class EventListTest extends TestCase
{
    private Event $kommend;

    private Event $vergangenOffen;

    private Event $abgeschlossen;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($this->admin());

        $promoter = Promoter::create(['name' => 'Konzertagentur']);
        $this->kommend = Event::create(['title' => 'Konzert', 'promoter_id' => $promoter->id, 'status' => 'bestätigt', 'starts_at' => now()->addWeek()]);
        $this->vergangenOffen = Event::create(['title' => 'Messe', 'status' => 'bestätigt', 'starts_at' => now()->subWeek()]);
        $this->abgeschlossen = Event::create(['title' => 'Gala', 'status' => 'bestätigt', 'starts_at' => now()->addMonth(), 'closed' => true]);
    }

    public function test_voreingestellt_offen_und_ab_heute(): void
    {
        Livewire::test(ListEvents::class)
            ->assertCanSeeTableRecords([$this->kommend])
            ->assertCanNotSeeTableRecords([$this->vergangenOffen, $this->abgeschlossen]);
    }

    public function test_vergangene_offene_events(): void
    {
        Livewire::test(ListEvents::class)
            ->filterTable('time', ['time' => 'past'])
            ->assertCanSeeTableRecords([$this->vergangenOffen])
            ->assertCanNotSeeTableRecords([$this->kommend]);

        $this->assertTrue($this->vergangenOffen->isOverdue());
        $this->assertFalse($this->kommend->isOverdue());
    }

    public function test_tab_abgeschlossen(): void
    {
        Livewire::test(ListEvents::class)
            ->set('activeTab', 'abgeschlossen')
            ->assertCanSeeTableRecords([$this->abgeschlossen])
            ->assertCanNotSeeTableRecords([$this->kommend]);
    }

    public function test_suche_nach_titel(): void
    {
        Livewire::test(ListEvents::class)
            ->set('activeTab', 'alle')
            ->filterTable('time', ['time' => 'all'])
            ->searchTable('Messe')
            ->assertCanSeeTableRecords([$this->vergangenOffen])
            ->assertCanNotSeeTableRecords([$this->kommend, $this->abgeschlossen]);
    }

    public function test_detailansicht(): void
    {
        $this->get('/events/' . $this->kommend->id)
            ->assertOk()
            ->assertSee('Konzert')
            ->assertSee('Konzertagentur');
    }

    public function test_leserecht_genuegt_fuer_die_liste(): void
    {
        $leser = $this->userWith($this->role('catering', ['events' => 'read']));

        $this->actingAs($leser)->get('/events')->assertOk();
    }
}
