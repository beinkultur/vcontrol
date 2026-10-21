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

    public function test_bei_haus_delay_ist_cobra_immer_erwartet(): void
    {
        $event = Event::create(['title' => 'Delay', 'starts_at' => now()->addWeek()]);
        $cobra = fn (): array => collect(IncomingInvoices::slots($event->fresh()))->firstWhere('key', 'cobra_hausdelay');
        $settled = fn (): bool => Event::query()->incomingInvoicesSettled()->whereKey($event->id)->exists();
        // Gespeichert „nicht erwartet“ – ohne Haus-Delay gilt das
        $event->incomingInvoices()->create(['invoice_key' => 'cobra_hausdelay', 'is_active' => false, 'is_received' => false]);
        $this->assertFalse($cobra()['active']);
        $this->assertTrue($settled());

        // Haus-Delay ja geht vor: erwartet, noch nicht da
        $event->operation()->create(['house_delay' => true]);
        $this->assertTrue($cobra()['active']);
        $this->assertFalse(IncomingInvoices::settled($event->fresh()));
        $this->assertSame('0/1', IncomingInvoices::summary($event->fresh()));
        $this->assertFalse($settled());

        // In der Buchhaltung an und gesperrt; „Rechnung da“ lässt sich setzen. Ohne Hinweis (Vorgabe vom 21.10.2026)
        $this->actingAs($this->admin());
        $this->get(\App\Filament\Resources\Accounting\AccountingResource::getUrl('dashboard', ['record' => $event]))
            ->assertOk()
            ->assertSee('Cobra – Haus-Delay')
            ->assertDontSee('Immer erwartet, weil Haus-Delay')
            // Auch ohne die Hinweise zum FIBU-Status und zur Durchführung (Vorgabe vom 21.10.2026)
            ->assertDontSee('heißt: Rechnung verschickt')
            ->assertDontSee('Was zusätzlich berechnet wird');
        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->assertSchemaStateSet(['invoice_cobra_hausdelay_active' => true])
            ->assertFormFieldIsDisabled('invoice_cobra_hausdelay_active')
            ->assertFormFieldIsEnabled('invoice_vfv_active')
            ->fillForm(['invoice_cobra_hausdelay_received' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($cobra()['received']);
        $this->assertTrue(IncomingInvoices::settled($event->fresh()));
        $this->assertTrue($settled());
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
