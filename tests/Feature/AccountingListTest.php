<?php

namespace Tests\Feature;

use App\Filament\Resources\Accounting\Pages\ListAccounting;
use App\Models\Event;
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
    private function event(string $title, array $finance, array $seating = [], bool $houseDelay = false): Event
    {
        $event = Event::create(['title' => $title, 'starts_at' => now()->addDays(3), 'seating' => $seating ?: null]);
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

        $tab = fn (string $name) => Livewire::test(ListAccounting::class)->set('activeTab', $name);

        $tab('offen')->assertCanSeeTableRecords([$offen])->assertCanNotSeeTableRecords([$abgeschlossen, $endabrechnung, $bestuhlt, $teilbestuhlt]);
        $tab('abgeschlossen')->assertCanSeeTableRecords([$abgeschlossen])->assertCanNotSeeTableRecords([$offen, $teilbestuhlt]);
        // „bestuhlt“ erwartet automatisch die Mobiliar-Rechnung – die fehlt noch
        $tab('endabrechnung')->assertCanSeeTableRecords([$endabrechnung, $bestuhlt])->assertCanNotSeeTableRecords([$offen, $teilbestuhlt]);
        // „teilbestuhlt“ ist nicht „bestuhlt“: nichts erwartet, also archivreif
        $tab('archiv')->assertCanSeeTableRecords([$teilbestuhlt])->assertCanNotSeeTableRecords([$bestuhlt, $endabrechnung]);
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
        $events[2]->incomingInvoices()->create(['invoice_key' => 'vfv', 'is_active' => true, 'is_received' => false]);

        foreach ($events as $event) {
            $this->assertSame(
                IncomingInvoices::settled($event->fresh()),
                Event::whereKey($event->id)->incomingInvoicesSettled()->exists(),
                "Abweichung bei {$event->title}",
            );
        }
    }

    public function test_nur_mit_recht_auf_die_buchhaltung(): void
    {
        $catering = $this->userWith($this->role('catering', ['events' => 'read']));
        $buchhaltung = $this->userWith($this->role('buchhaltung', ['buchhaltung' => 'read']));

        $this->actingAs($catering)->get('/buchhaltung')->assertForbidden();
        $this->actingAs($buchhaltung)->get('/buchhaltung')->assertOk();
    }
}
