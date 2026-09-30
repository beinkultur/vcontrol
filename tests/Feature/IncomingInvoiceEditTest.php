<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Support\IncomingInvoices;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class IncomingInvoiceEditTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek(), 'seating' => ['bestuhlt']]);
    }

    private function slot(string $key): array
    {
        return collect(IncomingInvoices::slots($this->event->fresh()))->firstWhere('key', $key);
    }

    public function test_unberuehrte_positionen_folgen_der_automatik(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->assertSchemaStateSet(['invoice_mobiliar_stuehle_active' => true, 'invoice_vfv_active' => false])
            ->fillForm(['title' => 'Konzert 2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, $this->event->incomingInvoices()->count(), 'nichts gespeichert, Automatik bleibt');
    }

    public function test_erwarten_und_eingang_markieren(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->fillForm(['invoice_vfv_active' => true, 'invoice_vfv_received' => true, 'invoice_mobiliar_stuehle_received' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->slot('vfv')['received']);
        $this->assertTrue($this->slot('mobiliar_stuehle')['received']);
        $this->assertSame('2/2', IncomingInvoices::summary($this->event->fresh()));
    }

    public function test_nicht_mehr_erwartet_setzt_eingang_zurueck(): void
    {
        $this->event->incomingInvoices()->create(['invoice_key' => 'vfv', 'is_active' => true, 'is_received' => true]);
        $this->actingAs($this->admin());

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->fillForm(['invoice_vfv_active' => false])
            ->call('save');

        $this->assertFalse($this->slot('vfv')['active']);
        $this->assertFalse($this->slot('vfv')['received']);
    }

    public function test_leserecht_buchhaltung_aendert_nichts(): void
    {
        $leser = $this->userWith($this->role('planer', ['events' => 'edit', 'buchhaltung' => 'read']));
        $this->actingAs($leser);

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->fillForm(['invoice_vfv_active' => true, 'invoice_vfv_received' => true])
            ->call('save');

        $this->assertSame(0, $this->event->incomingInvoices()->count());
    }

    public function test_finanz_warnung_wie_in_der_php_version(): void
    {
        $bald = Event::create(['title' => 'Bald', 'starts_at' => now()->addDays(10)]);
        $bald->finance()->create(['contract_status' => 'Vertrag zurück', 'accounting_status' => ['1. Rate gezahlt']]);
        $bezahlt = Event::create(['title' => 'Bezahlt', 'starts_at' => now()->addDays(10)]);
        $bezahlt->finance()->create(['contract_status' => 'Rahmenvertrag', 'accounting_status' => ['2. Rate gezahlt']]);
        $spaeter = Event::create(['title' => 'Später', 'starts_at' => now()->addDays(30)]);
        $spaeter->finance()->create(['contract_status' => 'Vertrag versendet']);
        $gemischt = Event::create(['title' => 'Gemischt', 'starts_at' => now()->addDays(5)]);
        $gemischt->finance()->create(['contract_status' => 'Vertrag zurück , Vertrag versendet', 'accounting_status' => ['2. Rate gezahlt']]);

        $this->assertTrue($bald->fresh()->hasFinanceAlert(), '2. Rate fehlt');
        $this->assertFalse($bezahlt->fresh()->hasFinanceAlert());
        $this->assertFalse($spaeter->fresh()->hasFinanceAlert(), 'mehr als 14 Tage entfernt');
        $this->assertFalse($gemischt->fresh()->hasFinanceAlert(), 'Komma-Wert „Vertrag zurück“ zählt');
    }
}
