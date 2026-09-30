<?php

namespace Tests\Feature;

use App\Enums\Responsible;
use App\Enums\ServiceCode;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\Trade;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class EventServicesTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($this->admin());
        $this->event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
    }

    private function edit(array $data): void
    {
        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->fillForm($data)
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_nur_befuellte_leistungen_werden_gespeichert(): void
    {
        $sidi = Trade::create(['name' => 'Sicherheitsdienst', 'categories' => ['Sicherheit']]);

        $this->edit([
            'service_security_responsible' => 'arena',
            'service_security_trade' => $sidi->id,
            'service_crew_catering_responsible' => 'promoter',
            'service_crew_catering_note' => 'Veganes Catering',
        ]);

        $services = $this->event->services()->get()->keyBy(fn ($s) => $s->service->value);
        $this->assertCount(2, $services, 'die übrigen 17 bleiben „nicht festgelegt“');
        $this->assertSame(Responsible::Arena, $services['security']->responsible);
        $this->assertSame($sidi->id, $services['security']->trade_id);
        $this->assertSame('Veganes Catering', $services['crew_catering']->note);
    }

    public function test_leeren_entfernt_die_leistung(): void
    {
        $this->event->services()->create(['service' => ServiceCode::Sfx, 'responsible' => Responsible::Promoter]);

        $this->edit(['service_sfx_responsible' => null]);

        $this->assertSame(0, $this->event->services()->count());
    }

    public function test_aktiv_schalter_bleibt_beim_leeren_erhalten(): void
    {
        $this->event->services()->create(['service' => ServiceCode::Vt, 'responsible' => Responsible::Arena, 'is_active' => true]);

        $this->edit(['service_vt_responsible' => null]);

        $vt = $this->event->services()->firstOrFail();
        $this->assertNull($vt->responsible);
        $this->assertTrue($vt->is_active);
    }

    public function test_gewerke_passend_zum_leistungsbereich(): void
    {
        $this->assertSame('Sicherheit', ServiceCode::Security->tradeCategory());
        $this->assertSame('Umbau', ServiceCode::ChairsTeardown->tradeCategory());
        $this->assertNull(ServiceCode::FireWatch->tradeCategory());
    }
}
