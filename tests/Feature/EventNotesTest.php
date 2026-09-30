<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\NotesRelationManager;
use App\Models\Event;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class EventNotesTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
    }

    public function test_notiz_anlegen_aendern_loeschen(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $manager = fn () => Livewire::test(NotesRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class]);

        // Leerraum wie in der PHP-Version bereinigt.
        $manager()->callTableAction('create', data: ['subject' => '  Aufbau   Licht ', 'body' => "Truss um 8 Uhr\r\nStrom ab 7  "])
            ->assertHasNoTableActionErrors();

        $note = $this->event->notes()->firstOrFail();
        $this->assertSame('Aufbau Licht', $note->subject);
        $this->assertSame("Truss um 8 Uhr\nStrom ab 7", $note->body);
        $this->assertSame($admin->id, $note->created_by);
        $this->assertSame($admin->getFilamentName(), $note->created_by_name);
        $this->assertNull($note->updated_by);

        $kollege = $this->userWith($this->role('technik', ['events' => 'edit']));
        $this->actingAs($kollege);
        $manager()->callTableAction('edit', $note, data: ['body' => 'Truss um 9 Uhr'])->assertHasNoTableActionErrors();
        $note->refresh();
        $this->assertSame('Truss um 9 Uhr', $note->body);
        $this->assertSame($admin->getFilamentName(), $note->created_by_name);
        $this->assertSame($kollege->getFilamentName(), $note->updated_by_name);

        $manager()->callTableAction('delete', $note);
        $this->assertSame(0, $this->event->notes()->count());
    }

    public function test_betreff_und_text_sind_pflicht(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(NotesRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class])
            ->callTableAction('create', data: ['subject' => '', 'body' => ''])
            ->assertHasTableActionErrors(['subject' => 'required', 'body' => 'required']);
    }

    public function test_leserolle_liest_aber_schreibt_nicht(): void
    {
        $note = $this->event->notes()->create(['subject' => 'Catering', 'body' => 'Vegetarisch für 12']);
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));

        Livewire::test(NotesRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => ViewEvent::class])
            ->assertCanSeeTableRecords([$note])
            ->assertSee('Vegetarisch für 12')
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $note)
            ->assertTableActionHidden('delete', $note)
            ->assertTableActionVisible('view', $note);
    }
}
