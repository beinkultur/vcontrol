<?php

namespace Tests\Feature;

use App\Access\Area;
use App\Access\Module;
use App\Access\Modules;
use App\Filament\Resources\Accounting\Pages\AccountingDashboard;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use App\Models\Meter;
use App\Models\Room;
use App\Models\Setting;
use App\Support\EventProgress;
use App\Support\ExecutionCheck;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Modul Betrieb (17.11.2026): Durchführung › Betrieb mit dem Durchführungs-Check
 * der Inselpark Arena ist abschaltbar. Ohne das Modul gilt die Durchführung als
 * erfasst – vergangene Events bleiben nicht unter „Zukünftige“ stehen.
 */
class OperationsModuleTest extends TestCase
{
    private Event $past;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($this->admin());
        // Vergangen, nichts unter Betrieb erfasst, Konzert ohne Raum
        $this->past = Event::create(['title' => 'Messe', 'status' => 'bestätigt', 'event_type1' => 'Konzert', 'starts_at' => now()->subWeek()]);
    }

    private function without(Module $off): void
    {
        Modules::current()->store(array_values(array_filter(Module::cases(), fn (Module $m): bool => $m !== $off)), []);
        app()->forgetScopedInstances();
    }

    public function test_reiter_betrieb_nur_mit_modul(): void
    {
        $url = EventResource::getUrl('edit', ['record' => $this->past]);
        $this->get($url)->assertOk()->assertSee('data-tab-key="betrieb"', false);

        $this->without(Module::Operations);
        $this->get($url)->assertOk()->assertDontSee('data-tab-key="betrieb"', false);
    }

    public function test_ohne_betrieb_bleibt_vergangenes_event_nicht_in_zukuenftige(): void
    {
        $this->assertNotSame([], ExecutionCheck::missing($this->past));
        Livewire::test(ListEvents::class)->assertCanSeeTableRecords([$this->past]);

        $this->without(Module::Operations);
        $this->assertSame([], ExecutionCheck::missing($this->past->fresh()));
        $this->assertSame([], ExecutionCheck::missingChecks($this->past->fresh()));
        $this->assertTrue(ExecutionCheck::isComplete($this->past->fresh()));
        $this->assertSame(0, Event::query()->executionIncomplete()->count());
        Livewire::test(ListEvents::class)->assertCanNotSeeTableRecords([$this->past]);
    }

    public function test_ohne_betrieb_zaehlt_nur_abgeschlossen(): void
    {
        $this->without(Module::Operations);
        $this->assertSame(0, EventProgress::phases($this->past->fresh())['durchfuehrung']);

        $this->past->update(['closed' => true]);
        $this->assertSame(100, EventProgress::phases($this->past->fresh())['durchfuehrung']);
    }

    public function test_buchhaltungs_dashboard_ohne_durchfuehrungs_teil(): void
    {
        Livewire::test(AccountingDashboard::class, ['record' => $this->past->getRouteKey()])->assertSee('Noch offen');

        $this->without(Module::Operations);
        Livewire::test(AccountingDashboard::class, ['record' => $this->past->getRouteKey()])->assertDontSee('Noch offen');
    }

    /** Räume werden nur unter Durchführung › Betrieb genutzt: der Bereich kommt mit dem Modul */
    public function test_raeume_kommen_mit_betrieb(): void
    {
        $this->assertSame(Module::Operations, Area::AdminRaeume->module());
        $this->assertFalse(Area::AdminRaeume->isSwitchable());
        $this->assertTrue($this->admin()->can('viewAny', Room::class));

        $this->without(Module::Operations);
        $this->assertTrue(Area::AdminRaeume->isDisabled());
        $this->assertFalse($this->admin()->can('viewAny', Room::class));
    }

    /** Stammdaten › Zähler: nur, wenn Betrieb (Stände je Event) oder Zählerstände (Seite /zaehler) frei sind */
    public function test_zaehler_stammdaten_nur_mit_betrieb_oder_zaehlerstaenden(): void
    {
        $this->assertTrue($this->admin()->can('viewAny', Meter::class));

        $this->without(Module::Operations);
        $this->assertTrue($this->admin()->can('viewAny', Meter::class), 'Zählerstände sind noch frei');

        Modules::current()->store(array_values(array_filter(Module::cases(), fn (Module $m): bool => $m !== Module::Operations)), [Area::Zaehler]);
        app()->forgetScopedInstances();
        $this->assertFalse($this->admin()->can('viewAny', Meter::class));
        $this->assertFalse($this->admin()->can('create', Meter::class));
    }

    public function test_migration_ergaenzt_betrieb_in_gespeicherten_modulen(): void
    {
        $migration = require database_path('migrations/2026_11_17_120000_add_operations_module_to_stored_modules.php');

        // Ohne Eintrag: alle Module frei, nichts zu tun
        $migration->up();
        $this->assertNull(Setting::lookup(Setting::MODULES));

        // ipa: gespeicherte Liste – Betrieb kommt dazu, genau einmal
        Setting::put(Setting::MODULES, 'buehnenplan,saalplan,gaesteliste,buchhaltung,preislisten');
        $migration->up();
        $migration->up();
        $this->assertSame('buehnenplan,saalplan,gaesteliste,buchhaltung,preislisten,betrieb', Setting::lookup(Setting::MODULES));

        // Bewusst alles aus: bleibt aus
        Setting::put(Setting::MODULES, '');
        $migration->up();
        $this->assertSame('', Setting::lookup(Setting::MODULES));
    }
}
