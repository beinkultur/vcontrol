<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class EventChecklistTest extends TestCase
{
    public function test_checkliste_speichern(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($this->admin());
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);

        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm([
                'checklist.hands' => 'yes',
                'checklist.chairs_ordered' => 'no',
                'checklist.traffic' => 'na',
                'house_rig_early' => 1, // seit 01.10.2026 unter Durchführung › Betrieb
                // seit 12.10.2026 ebenfalls dort, „entfällt“ bleibt möglich
                'merch_fee' => '15 %',
                'merch_fee_check' => 'na',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $checklist = $event->checklist()->firstOrFail();
        $this->assertSame('yes', $checklist->hands);
        $this->assertSame('no', $checklist->chairs_ordered);
        $this->assertSame('na', $checklist->traffic);
        $this->assertSame('15 %', $checklist->merch_fee);
        $this->assertSame('na', $checklist->merch_fee_check);
        $this->assertTrue($checklist->house_rig_early);

        // Wiedergeladen stehen die Werte unter Betrieb › Check, nicht mehr in der Planung
        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->assertFormSet(['merch_fee' => '15 %', 'merch_fee_check' => 'na'])
            ->assertFormFieldExists('merch_fee')
            ->assertFormFieldDoesNotExist('checklist.merch_fee')
            ->assertFormFieldDoesNotExist('checklist.merch_fee_check')
            ->assertFormFieldDoesNotExist('checklist.briefing_complete');
    }

    public function test_planung_fragt_ob_die_positionen_geplant_sind(): void
    {
        $this->actingAs($this->admin());
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);

        $this->get(EventResource::getUrl('edit', ['record' => $event]) . '?phase=planung&bereich=checkliste')
            ->assertOk()
            ->assertSeeInOrder(['Sind folgende Positionen geplant?', 'Hands', 'Stühle bestellt'])
            ->assertDontSee('Briefing vollständig');
    }
}
