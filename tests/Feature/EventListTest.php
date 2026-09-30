<?php

namespace Tests\Feature;

use App\Enums\AssignmentRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Employee;
use App\Models\Event;
use App\Models\Promoter;
use App\Support\EventDisplay;
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

    public function test_status_abgeschlossen(): void
    {
        Livewire::test(ListEvents::class)
            ->filterTable('state', ['state' => 'closed'])
            ->assertCanSeeTableRecords([$this->abgeschlossen])
            ->assertCanNotSeeTableRecords([$this->kommend]);
    }

    public function test_suche_nach_titel(): void
    {
        Livewire::test(ListEvents::class)
            ->filterTable('state', ['state' => 'all'])
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

    public function test_spalten_wie_in_der_php_version(): void
    {
        $anna = Employee::create(['first_name' => 'Anna', 'last_name' => 'Leitung', 'initials' => 'AL']);
        $this->kommend->assignments()->create(['role' => AssignmentRole::ProjectLead, 'assignee_type' => 'employee', 'assignee_id' => $anna->id]);
        $this->kommend->update(['seating' => ['bestuhlt'], 'event_type1' => 'Konzert', 'event_type2' => 'Pop / Rock']);
        $fraglich = Event::create(['title' => 'Vielleicht', 'status' => 'fraglich', 'starts_at' => now()->addDays(10)]);

        Livewire::test(ListEvents::class)
            ->assertTableColumnStateSet('project_lead', 'AL', $this->kommend)
            ->assertTableColumnStateSet('seated', '🪑', $this->kommend)
            ->assertTableColumnStateSet('category', 'Pop / Rock', $this->kommend)
            ->assertTableColumnStateSet('promoter_short', 'Konzertagentur', $this->kommend)
            ->assertSee(EventDisplay::month($this->kommend->starts_at))
            ->assertSee('vc-row--fraglich')
            ->assertCanSeeTableRecords([$fraglich]);
    }

    public function test_klick_oeffnet_den_workspace(): void
    {
        $leser = $this->userWith($this->role('catering', ['events' => 'read']));

        Livewire::test(ListEvents::class)
            ->assertSee(EventResource::getUrl('edit', ['record' => $this->kommend]));
        $this->actingAs($leser);
        Livewire::test(ListEvents::class)
            ->assertSee(EventResource::getUrl('view', ['record' => $this->kommend]))
            ->assertDontSee(EventResource::getUrl('edit', ['record' => $this->kommend]));
    }

    public function test_leserecht_genuegt_fuer_die_liste(): void
    {
        $leser = $this->userWith($this->role('catering', ['events' => 'read']));

        $this->actingAs($leser)->get('/events')->assertOk();
    }
}
