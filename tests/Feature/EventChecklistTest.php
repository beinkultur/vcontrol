<?php

namespace Tests\Feature;

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
                'checklist.merch_fee' => '15 %',
                'house_rig_early' => 1, // seit 01.10.2026 unter Durchführung › Betrieb
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $checklist = $event->checklist()->firstOrFail();
        $this->assertSame('yes', $checklist->hands);
        $this->assertSame('no', $checklist->chairs_ordered);
        $this->assertSame('na', $checklist->traffic);
        $this->assertSame('15 %', $checklist->merch_fee);
        $this->assertTrue($checklist->house_rig_early);
    }
}
