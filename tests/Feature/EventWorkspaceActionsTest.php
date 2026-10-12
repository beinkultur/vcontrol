<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use Filament\Facades\Filament;
use Filament\Support\Enums\Alignment;
use Livewire\Livewire;
use Tests\TestCase;

class EventWorkspaceActionsTest extends TestCase
{
    public function test_speichern_und_abbrechen_auch_in_der_reiterzeile(): void
    {
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);

        // Je Phase mit Unterbereichen (Buchung, Planung, Durchführung) einmal in der Reiterzeile
        $html = $this->actingAs($this->admin())
            ->get(EventResource::getUrl('edit', ['record' => $event]))
            ->assertOk()
            ->getContent();
        $this->assertSame(3, substr_count($html, 'class="vc-sections__actions"'));
        $this->assertStringContainsString('type="submit"', $html);

        // Unten rechtsbündig
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->assertSame(Alignment::End, Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])->instance()->getFormActionsAlignment());

        // Die Lese-Ansicht hat nichts zu speichern
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])))
            ->get(EventResource::getUrl('view', ['record' => $event]))
            ->assertOk()
            ->assertDontSee('vc-sections__actions');
    }
}
