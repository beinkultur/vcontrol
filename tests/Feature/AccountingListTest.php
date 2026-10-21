<?php

namespace Tests\Feature;

use App\Filament\Resources\Accounting\AccountingResource;
use App\Filament\Resources\Accounting\Pages\AccountingDashboard;
use App\Filament\Resources\Accounting\Pages\ListAccounting;
use App\Models\Event;
use App\Models\FieldOption;
use App\Models\Promoter;
use App\Support\AccountingChecks;
use App\Support\IncomingInvoices;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class AccountingListTest extends TestCase
{
    private const FINAL = 'Endabrechnung gestellt';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($this->admin());
    }

    /** @param  array<string, mixed>  $finance */
    private function event(string $title, array $finance, array $seating = [], bool $houseDelay = false, int $days = 3, array $attributes = []): Event
    {
        $event = Event::create(['title' => $title, 'starts_at' => now()->addDays($days), 'seating' => $seating ?: null, ...$attributes]);
        $event->finance()->create($finance);
        $event->operation()->create(['house_delay' => $houseDelay]);

        return $event->fresh();
    }

    public function test_listen_wie_in_der_php_version(): void
    {
        $offen = $this->event('Offen', ['accounting_status' => ['1. Rate gezahlt']]);
        $abgeschlossen = $this->event('Abgeschlossen', ['accounting_closed' => true]);
        $endabrechnung = $this->event('Endabrechnung', ['accounting_status' => [self::FINAL]]);
        $bestuhlt = $this->event('Bestuhlt', ['accounting_status' => [self::FINAL], 'accounting_closed' => true], ['bestuhlt']);
        $teilbestuhlt = $this->event('Teilbestuhlt', ['accounting_status' => [self::FINAL], 'accounting_closed' => true], ['teilbestuhlt']);

        // Seit 21.10.2026 im Filter „Liste“ statt in Reitern, voreingestellt „Offen“
        $list = fn (?string $state = null) => $state === null
            ? Livewire::test(ListAccounting::class)
            : Livewire::test(ListAccounting::class)->filterTable('state', ['state' => $state]);

        $list()->assertCanSeeTableRecords([$offen])->assertCanNotSeeTableRecords([$abgeschlossen, $endabrechnung, $bestuhlt, $teilbestuhlt]);
        $list('abgeschlossen')->assertCanSeeTableRecords([$abgeschlossen])->assertCanNotSeeTableRecords([$offen, $teilbestuhlt]);
        // „bestuhlt“ erwartet automatisch die Mobiliar-Rechnung – die fehlt noch
        $list('endabrechnung')->assertCanSeeTableRecords([$endabrechnung, $bestuhlt])->assertCanNotSeeTableRecords([$offen, $teilbestuhlt]);
        // „teilbestuhlt“ ist nicht „bestuhlt“: nichts erwartet, also archivreif
        $list('archiv')->assertCanSeeTableRecords([$teilbestuhlt])->assertCanNotSeeTableRecords([$bestuhlt, $endabrechnung]);
        $list('alle')->assertCanSeeTableRecords([$offen, $abgeschlossen, $endabrechnung, $bestuhlt, $teilbestuhlt]);
    }

    public function test_spalten_wie_vorgegeben_und_klick_oeffnet_das_dashboard(): void
    {
        $event = $this->event('Konzert', ['contract_status' => 'Vertrag zurück', 'accounting_status' => ['1. Rate / Grundmiete gestellt'], 'invoice_numbers' => ['1088']]);

        $component = Livewire::test(ListAccounting::class);
        foreach (['starts_at', 'status', 'title', 'promoter_short', 'va_id', 'finance.contract_status', 'finance.accounting_status', 'finance.invoice_numbers', 'incoming_invoices', 'paid'] as $column) {
            $component->assertTableColumnExists($column);
        }
        foreach (['finance.rent', 'operation.bus_power', 'extra_podests', 'finance.accounting_closed'] as $column) {
            $component->assertTableColumnDoesNotExist($column);
        }

        $this->get(AccountingResource::getUrl())
            ->assertOk()
            ->assertSee('1088')
            ->assertSee(AccountingResource::getUrl('dashboard', ['record' => $event]), false);
    }

    public function test_sortieren_per_klick_auf_die_spalte(): void
    {
        $zeta = Promoter::create(['name' => 'Zeta Konzerte']);
        $alpha = Promoter::create(['name' => 'Alpha Events']);
        $a = $this->event('Früh', ['contract_status' => 'Vertrag versendet'], days: 2, attributes: ['promoter_id' => $zeta->id, 'va_id' => '300']);
        $b = $this->event('Spät', ['contract_status' => 'Rahmenvertrag'], days: 40, attributes: ['promoter_id' => $alpha->id, 'va_id' => '100']);
        $c = $this->event('Mitte', ['contract_status' => 'Vertrag zurück'], days: 20, attributes: ['va_id' => '200']);

        $component = Livewire::test(ListAccounting::class)
            ->assertCanSeeTableRecords([$a, $c, $b], inOrder: true);
        $this->assertNotNull($component->instance()->getTableGrouping(), 'nach Datum: Monatsgruppen');

        $component->sortTable('title')->assertCanSeeTableRecords([$a, $c, $b], inOrder: true);
        $this->assertNull($component->instance()->getTableGrouping(), 'nach anderer Spalte: ohne Monatsgruppen');
        $component->sortTable('title', 'desc')->assertCanSeeTableRecords([$b, $c, $a], inOrder: true);
        $component->sortTable('va_id')->assertCanSeeTableRecords([$b, $c, $a], inOrder: true);
        $component->sortTable('finance.contract_status')->assertCanSeeTableRecords([$b, $a, $c], inOrder: true);
        $component->sortTable('promoter_short', 'desc')->assertCanSeeTableRecords([$a, $b], inOrder: true);
        $component->sortTable('starts_at', 'desc')->assertCanSeeTableRecords([$b, $c, $a], inOrder: true);
        $this->assertNotNull($component->instance()->getTableGrouping());

        foreach (['status', 'finance.accounting_status', 'finance.invoice_numbers', 'incoming_invoices', 'paid'] as $column) {
            $component->sortTable($column)->assertCanSeeTableRecords([$a, $b, $c]);
        }
    }

    public function test_filter_pruefen(): void
    {
        $vertrag = $this->event('Vertrag versendet', ['contract_status' => 'Vertrag versendet', 'accounting_status' => ['1. Rate / Grundmiete gezahlt', '2. Rate gezahlt']], days: 30);
        $vertragZurueck = $this->event('Vertrag zurück', ['contract_status' => 'Vertrag versendet, Vertrag zurück', 'accounting_status' => ['1. Rate / Grundmiete gezahlt', '2. Rate gezahlt']], days: 30);
        $rechnung = $this->event('Rechnung offen', ['contract_status' => 'Rahmenvertrag', 'accounting_status' => ['1. Rate / Grundmiete gezahlt', '2. Rate gestellt']], days: 30);
        // In 3 Tagen, Vertrag nicht zurück: rotes Ausrufezeichen
        $warnung = $this->event('Warnung', ['contract_status' => 'Vertrag erstellt'], days: 3);
        $eingang = $this->event('Eingang fehlt', ['contract_status' => 'Vertrag zurück', 'accounting_status' => ['1. Rate / Grundmiete gezahlt', '2. Rate gezahlt']], ['bestuhlt'], days: 30, attributes: ['closed' => true]);

        $check = fn (string $check) => Livewire::test(ListAccounting::class)->filterTable('check', ['check' => $check]);

        $check(AccountingChecks::CONTRACT_OPEN)->assertCanSeeTableRecords([$vertrag])->assertCanNotSeeTableRecords([$vertragZurueck, $rechnung, $eingang]);
        $check(AccountingChecks::INVOICE_OPEN)->assertCanSeeTableRecords([$rechnung])->assertCanNotSeeTableRecords([$vertrag, $eingang]);
        $check(AccountingChecks::FINANCE_ALERT)->assertCanSeeTableRecords([$warnung])->assertCanNotSeeTableRecords([$vertrag, $rechnung, $eingang]);
        $check(AccountingChecks::INCOMING_MISSING)->assertCanSeeTableRecords([$eingang])->assertCanNotSeeTableRecords([$vertrag, $rechnung, $warnung]);

        $this->get(AccountingResource::getUrl())->assertSee('Prüfen')->assertSee('Vertrag versendet, nicht zurück');
    }

    public function test_haken_wenn_alle_verschickten_rechnungen_bezahlt(): void
    {
        $paid = fn (array $status): bool => AccountingChecks::allPaid($this->event('E', ['accounting_status' => $status]));

        $this->assertFalse($paid([]), 'nichts verschickt');
        $this->assertFalse($paid(['1. Rate / Grundmiete gestellt']));
        $this->assertTrue($paid(['1. Rate / Grundmiete gestellt', '1. Rate / Grundmiete gezahlt']));
        $this->assertTrue($paid(['1. Rate / Grundmiete gezahlt']), 'nur gezahlt angehakt: war auch verschickt');
        $this->assertFalse($paid(['1. Rate / Grundmiete gezahlt', '2. Rate gestellt']));
        $this->assertTrue($paid(['1. Rate / Grundmiete gezahlt', '2. Rate gestellt', '2. Rate gezahlt']));
        // In der Liste je Rechnung ein Wert
        $this->assertSame(['1. Rate / Grundmiete gezahlt', '2. Rate gestellt', 'Sonstiges'],
            AccountingChecks::condensedStatus($this->event('F', ['accounting_status' => ['1. Rate / Grundmiete gestellt', '1. Rate / Grundmiete gezahlt', '2. Rate gestellt', 'Sonstiges']])));
        // Ohne Option „Endabrechnung gezahlt“ bleibt die Endabrechnung offen
        $this->assertFalse($paid(['1. Rate / Grundmiete gezahlt', self::FINAL]));
        $this->assertTrue($paid(['1. Rate / Grundmiete gezahlt', self::FINAL, 'Endabrechnung gezahlt']));
    }

    public function test_eingangsrechnungen_automatik_und_ausdruecklicher_eintrag(): void
    {
        $delay = $this->event('Delay', ['accounting_status' => [self::FINAL], 'accounting_closed' => true], [], houseDelay: true);
        $this->assertSame('0/1', IncomingInvoices::summary($delay));
        $this->assertFalse(Event::whereKey($delay->id)->archiveReady()->exists());

        $delay->incomingInvoices()->create(['invoice_key' => 'cobra_hausdelay', 'is_active' => true, 'is_received' => true]);
        $delay = $delay->fresh();
        $this->assertSame('1/1', IncomingInvoices::summary($delay));
        $this->assertTrue(Event::whereKey($delay->id)->archiveReady()->exists());

        // Automatische Position ausdrücklich abgeschaltet: nichts erwartet
        $stuehle = $this->event('Stühle', ['accounting_status' => [self::FINAL], 'accounting_closed' => true], ['bestuhlt']);
        $stuehle->incomingInvoices()->create(['invoice_key' => 'mobiliar_stuehle', 'is_active' => false]);
        $this->assertNull(IncomingInvoices::summary($stuehle->fresh()));
        $this->assertTrue(Event::whereKey($stuehle->id)->archiveReady()->exists());
    }

    public function test_sql_und_php_regel_stimmen_ueberein(): void
    {
        $events = [
            $this->event('A', ['accounting_status' => [self::FINAL], 'accounting_closed' => true], ['bestuhlt', 'teilbestuhlt']),
            $this->event('B', ['accounting_status' => [self::FINAL], 'accounting_closed' => true], [], houseDelay: true),
            $this->event('C', ['accounting_status' => [self::FINAL], 'accounting_closed' => true]),
        ];
        $events[2]->incomingInvoices()->create(['invoice_key' => 'vfv_2', 'is_active' => true, 'is_received' => false]);

        foreach ($events as $event) {
            $this->assertSame(
                IncomingInvoices::settled($event->fresh()),
                Event::whereKey($event->id)->incomingInvoicesSettled()->exists(),
                "Abweichung bei {$event->title}",
            );
        }
        $this->assertSame('0/1', IncomingInvoices::summary($events[2]->fresh()), 'VfV 2 zählt mit');
    }

    public function test_nur_mit_recht_auf_die_buchhaltung(): void
    {
        $event = $this->event('Konzert', []);
        $catering = $this->userWith($this->role('catering', ['events' => 'read']));
        $buchhaltung = $this->userWith($this->role('buchhaltung', ['buchhaltung' => 'read']));

        $this->actingAs($catering)->get('/buchhaltung')->assertForbidden();
        $this->get(AccountingResource::getUrl('dashboard', ['record' => $event]))->assertForbidden();
        $this->actingAs($buchhaltung)->get('/buchhaltung')->assertOk();
        $this->get(AccountingResource::getUrl('dashboard', ['record' => $event]))->assertOk();
    }

    public function test_dashboard_buchhaltung_bearbeitet_auch_ohne_event_recht(): void
    {
        foreach (['Vertrag versendet', 'Vertrag zurück'] as $value) {
            FieldOption::create(['field_key' => 'contract_status', 'value' => $value]);
        }
        foreach (['1. Rate / Grundmiete gestellt', '1. Rate / Grundmiete gezahlt'] as $value) {
            FieldOption::create(['field_key' => 'accounting_status', 'value' => $value]);
        }
        $event = $this->event('Konzert', ['contract_status' => 'Vertrag versendet', 'accounting_closed' => true], days: -5, attributes: ['wlan_password' => 'geheimes-wlan']);
        $event->operation->update(['bus_power' => 2]);
        $event->checklist()->create(['special_cleaning' => 'yes', 'merch_fee' => '20 %']);

        // Rolle Buchhaltung: Events nur lesen, Buchhaltung bearbeiten (wie auf ipa und Demo)
        $this->actingAs($this->userWith($this->role('buchhaltung', ['events' => 'read', 'buchhaltung' => 'edit'])));
        $this->get(AccountingResource::getUrl('dashboard', ['record' => $event]))
            ->assertOk()
            ->assertSee('Finanzen')
            ->assertSee('PAX abgerechnet')
            ->assertSee('Durchführung')
            ->assertSee('2 Anschlüsse')
            ->assertSee('20 %')
            ->assertSee('Personenlift')
            ->assertSee('VfV 1')
            ->assertSee('VfV 2')
            ->assertDontSee('VfVs')
            ->assertSee('Event öffnen')
            // Nicht im Seitenzustand: Felder, die das Dashboard nicht braucht (WLAN-Passwort nur für Event-Bearbeiter)
            ->assertDontSee('geheimes-wlan');

        // „Abrechnung abgeschlossen“ lässt sich zurücksetzen, PAX abgerechnet steht hier
        Livewire::test(AccountingDashboard::class, ['record' => $event->getRouteKey()])
            ->assertSchemaStateSet(['finance.accounting_closed' => true])
            ->fillForm([
                'finance.accounting_closed' => false,
                'finance.contract_status' => 'Vertrag zurück',
                'finance.accounting_status' => ['1. Rate / Grundmiete gestellt', '1. Rate / Grundmiete gezahlt'],
                'pax' => 2800,
                'invoice_vfv_2_active' => true,
                'invoice_vfv_2_received' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $event->refresh();
        $this->assertFalse($event->finance->accounting_closed);
        $this->assertSame('Vertrag zurück', $event->finance->contract_status);
        $this->assertSame(2800, $event->pax);
        $this->assertTrue(AccountingChecks::allPaid($event));
        $this->assertTrue((bool) $event->incomingInvoices()->where('invoice_key', 'vfv_2')->value('is_received'));

        // Nur lesen: sieht alles, ändert nichts
        $this->actingAs($this->userWith($this->role('controlling', ['buchhaltung' => 'read'])));
        Livewire::test(AccountingDashboard::class, ['record' => $event->getRouteKey()])
            ->assertFormFieldDisabled('pax')
            ->assertFormFieldDisabled('finance.accounting_closed')
            ->call('save')
            ->assertForbidden();
        $this->assertSame(2800, $event->fresh()->pax);
    }
}
