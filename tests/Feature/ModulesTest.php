<?php

namespace Tests\Feature;

use App\Access\Area;
use App\Access\Module;
use App\Access\Modules;
use App\Enums\OptionField;
use App\Filament\Pages\ManageSystem;
use App\Filament\Pages\ManageVenue;
use App\Filament\Resources\Accounting\Pages\ListAccounting;
use App\Filament\Resources\Costings\CostingResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\PriceLists\PriceListResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\SeatingPlans\SeatingPlanResource;
use App\Mail\DaysheetMail;
use App\Models\Costing;
use App\Models\Event;
use App\Models\EventGuest;
use App\Models\PriceList;
use App\Models\SeatingPlan;
use App\Models\Setting;
use App\Support\Daysheets;
use App\Support\EventProgress;
use App\Support\ExternSheet;
use App\Support\StageSettings;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Gebuchte Module (Verwaltung › System): ohne Freigabe für alle ausgeblendet, Eingegebenes bleibt. */
class ModulesTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Storage::fake(SeatingPlan::DISK);
        $this->event = Event::create(['title' => 'Konzert', 'status' => 'bestätigt', 'starts_at' => now()->addDays(3)]);
        $this->event->stage()->create(['width' => 14, 'depth' => 8, 'height' => 1.4]);
    }

    /** Nur diese Module frei, wie nach dem Speichern unter System */
    private function only(Module ...$modules): void
    {
        Modules::current()->store($modules, []);
    }

    /** Link aus der Daysheet-Mail an das Event */
    private function daysheetToken(): string
    {
        Mail::fake();
        Daysheets::send($this->event, ['buero@halle.de'], [], 'Daysheet {event}', "Hallo,\n{link}");
        preg_match('#/daysheet/([A-Za-z0-9]{48})#', Mail::sent(DaysheetMail::class)->last()->text, $match);

        return $match[1];
    }

    public function test_ohne_gespeicherte_auswahl_sind_alle_module_frei(): void
    {
        $this->assertNull(Setting::lookup(Setting::MODULES));
        foreach (Module::cases() as $module) {
            $this->assertTrue($module->isEnabled(), $module->label());
        }
        $this->assertFalse(Area::Buchhaltung->isDisabled());
    }

    public function test_system_admin_gibt_module_frei(): void
    {
        $this->actingAs($this->systemAdmin());

        Livewire::test(ManageSystem::class)
            ->assertSchemaStateSet(['modules' => ['buehnenplan', 'saalplan', 'gaesteliste', 'buchhaltung', 'preislisten']])
            // Die Buchhaltung steht nicht mehr unter Bereiche, sie kommt mit ihrem Modul
            ->assertFormFieldExists('areas_arbeitsbereiche', fn (CheckboxList $field): bool => !array_key_exists('buchhaltung', $field->getOptions())
                && array_key_exists('events', $field->getOptions()))
            ->fillForm(['modules' => ['saalplan', 'buchhaltung']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('saalplan,buchhaltung', Setting::lookup(Setting::MODULES));
        $this->assertFalse(Module::StagePlan->isEnabled());
        $this->assertFalse(Module::GuestList->isEnabled());
        $this->assertTrue(Module::SeatingPlan->isEnabled());
        $this->assertTrue(Module::Accounting->isEnabled());

        Livewire::test(ManageSystem::class)
            ->assertSchemaStateSet(['modules' => ['saalplan', 'buchhaltung']])
            ->fillForm(['modules' => []])
            ->call('save');

        $this->assertTrue(Area::Buchhaltung->isDisabled());
        $this->assertFalse($this->systemAdmin()->access()->can(Area::Buchhaltung), 'auch für den System-Admin');
        $this->assertFalse($this->admin()->access()->can(Area::Buchhaltung));
    }

    public function test_alle_bereiche_aus_laesst_die_buchhaltung_beim_modul(): void
    {
        $this->actingAs($this->systemAdmin());

        Livewire::test(ManageSystem::class)
            ->fillForm(['areas_arbeitsbereiche' => [], 'areas_administration' => [], 'areas_extern' => []])
            ->call('save');

        $this->assertTrue(Area::Events->isDisabled());
        $this->assertFalse(Area::Buchhaltung->isDisabled());
    }

    public function test_buchhaltung_als_abgeschalteter_bereich_wie_vor_den_modulen(): void
    {
        config(['venuecontrol.disabled_areas' => ['buchhaltung']]);
        $this->assertFalse(Module::Accounting->isEnabled());
        $this->assertTrue(Area::Buchhaltung->isDisabled());

        // Die Seite zeigt es so an; gespeichert gilt nur noch das Modul
        $this->actingAs($this->systemAdmin());
        Livewire::test(ManageSystem::class)
            ->assertSchemaStateSet(['modules' => ['buehnenplan', 'saalplan', 'gaesteliste', 'preislisten']])
            ->fillForm(['modules' => ['buehnenplan', 'saalplan', 'gaesteliste', 'buchhaltung', 'preislisten']])
            ->call('save');

        $this->assertTrue(Module::Accounting->isEnabled());
        $this->assertFalse(Area::Buchhaltung->isDisabled());
    }

    public function test_ohne_buehnenplan_keine_buehne(): void
    {
        $this->assertSame(18, EventProgress::phases($this->event->fresh())['planung'], 'Bühnenmaße und Höhe: 2 von 11');
        $this->assertSame('14 × 8 m', ExternSheet::make($this->event->fresh(), fn (): string => '')['stage']['rows']['Hauptbühne']);
        Setting::put('stage_label', 'Halle 2 stage');
        $token = $this->daysheetToken();

        $this->only(Module::SeatingPlan, Module::GuestList, Module::Accounting);
        $this->actingAs($this->admin());

        $this->get(EventResource::getUrl('stage-plan', ['record' => $this->event]))->assertForbidden();
        $this->get(route('events.stage-plan-print', $this->event))->assertNotFound();
        $this->get(route('events.stage-plan-svg', $this->event))->assertNotFound();

        // Workspace ohne Reiter Bühne; der Sold-Out-Award unter Durchführung › Betrieb bleibt
        $this->get(EventResource::getUrl('edit', ['record' => $this->event]))
            ->assertOk()
            ->assertSee('Sold-Out-Award')
            ->assertDontSee('Hauptbühne')
            ->assertDontSee('Bühnenplan')
            ->assertDontSee('bereich=buehne', false);
        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->assertFormFieldDoesNotExist('stage.width')
            ->assertFormFieldExists('sold_out_award');
        $this->assertSame(0, EventProgress::phases($this->event->fresh())['planung'], 'ohne Bühne: 0 von 9');
        $this->assertArrayNotHasKey('buehne', EventProgress::planningAreas($this->event->fresh(), 0, 0));

        // Keine Spalte Bühne in der Event-Liste, keine Zusatzpodeste in der Buchhaltung
        Livewire::test(ListEvents::class)->assertTableColumnHidden('stage_summary');
        Livewire::test(ListAccounting::class)->assertTableColumnHidden('extra_podests');

        // Halle ohne Abschnitt Bühne; die Werte bleiben beim Speichern
        Livewire::test(ManageVenue::class)
            ->assertDontSee('Standardbühne')
            ->fillForm(['venue_name' => 'Inselpark Arena'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Halle 2 stage', StageSettings::load()->label);

        // Für Externe und im Daysheet keine Bühne
        $this->assertNull(ExternSheet::make($this->event->fresh(), fn (): string => '')['stage']);
        $this->assertSame('Zeiten, Ansprechpartner, Gewerke, Dateien, Notizen und Saalplan', ExternSheet::contents());
        $this->get('/daysheet/' . $token)
            ->assertOk()
            ->assertDontSee('Hauptbühne')
            ->assertDontSee('Bühnenplan')
            ->assertDontSee('<svg', false);

        // Die Maße bleiben gespeichert
        $this->assertSame(14, (int) $this->event->stage()->firstOrFail()->width);
    }

    public function test_ohne_saalplan_bleiben_bereiche_und_bestuhlung(): void
    {
        Storage::disk(SeatingPlan::DISK)->put(SeatingPlan::DIRECTORY . '/konzert.pdf', '%PDF-1.4 Saalplan');
        $plan = SeatingPlan::create(['name' => 'Konzert bestuhlt', 'path' => SeatingPlan::DIRECTORY . '/konzert.pdf', 'areas' => ['Innenraum'], 'seating' => ['bestuhlt']]);
        $this->event->update(['seating_plan_id' => $plan->id, 'areas' => ['Innenraum'], 'seating' => ['bestuhlt']]);
        $token = $this->daysheetToken();

        $this->only(Module::StagePlan, Module::GuestList, Module::Accounting);
        $this->actingAs($this->admin());

        $this->assertFalse(SeatingPlanResource::canAccess(), 'nicht in der Navigation');
        $this->get(SeatingPlanResource::getUrl())->assertForbidden();
        $this->get(route('seating-plans.pdf', $plan))->assertNotFound();
        $this->get(route('events.seating-plan', $this->event))->assertNotFound();

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->assertFormFieldExists('areas')
            ->assertFormFieldExists('seating')
            ->assertFormFieldDoesNotExist('seating_plan_source')
            ->assertFormFieldDoesNotExist('seating_plan_id')
            ->assertFormFieldDoesNotExist('seating_plan_path');

        // Die Buchhaltung ändert ohne das Modul nichts mehr in der Leseansicht
        $this->actingAs($this->userWith($this->role('buchhaltung', ['events' => 'read', 'buchhaltung' => 'edit'])));
        Livewire::test(ViewEvent::class, ['record' => $this->event->getRouteKey()])
            ->assertDontSee('Saalplan ändern');

        // Kein Abschnitt Saalplan für Externe und im Daysheet
        $this->assertNull(ExternSheet::make($this->event->fresh(), fn (): string => '')['seating_plan']);
        $this->get('/daysheet/' . $token)->assertOk()->assertDontSee('Saalplan')->assertDontSee('Konzert bestuhlt');
        $this->get(route('daysheet.seating-plan', $token))->assertNotFound();

        // Gespeichert bleibt alles – mit dem Modul ist es wieder da
        $this->assertSame($plan->id, $this->event->fresh()->seating_plan_id);
        $this->only(...Module::cases());
        $this->get('/daysheet/' . $token)->assertOk()->assertSee('Saalplan')->assertSee('Konzert bestuhlt');
    }

    public function test_ohne_gaesteliste(): void
    {
        $this->event->guests()->create(['first_name' => 'Erika', 'last_name' => 'Muster', 'free_tickets' => 2]);
        $this->only(Module::StagePlan, Module::SeatingPlan, Module::Accounting);
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->get(route('events.guest-list-print', $this->event))->assertNotFound();
        $this->get(EventResource::getUrl('edit', ['record' => $this->event]))
            ->assertOk()
            ->assertSee('Planungsbereiche')
            ->assertDontSee('Gästeliste')
            ->assertDontSee('Muster');
        $this->assertFalse($admin->can('viewAny', EventGuest::class));
        $this->assertFalse($admin->can('create', EventGuest::class));
        $this->assertArrayNotHasKey('gaeste', EventProgress::planningAreas($this->event->fresh(), 1, 0));

        // Die Gäste bleiben gespeichert
        $this->assertSame(1, $this->event->guests()->count());
        $this->only(...Module::cases());
        $this->get(EventResource::getUrl('edit', ['record' => $this->event]))->assertSee('Gästeliste');
    }

    public function test_ohne_preislisten_kein_costing(): void
    {
        $list = PriceList::create(['name' => 'Konzert-Preisliste 2025-09']);
        $list->items()->create(['name' => 'Grundmiete', 'unit' => 'pauschal', 'price' => 2800, 'default_factor' => 1]);
        $this->event->update(['price_list_id' => $list->id]);
        $costing = Costing::createFor($this->event, $list);

        $this->only(Module::StagePlan, Module::SeatingPlan, Module::GuestList, Module::Accounting);
        $this->actingAs($this->admin());

        $this->assertFalse(PriceListResource::canAccess(), 'nicht in der Navigation');
        $this->assertFalse(CostingResource::canAccess());
        $this->get(PriceListResource::getUrl())->assertForbidden();
        $this->get(CostingResource::getUrl('view', ['record' => $costing]))->assertForbidden();
        $this->get(route('costings.pdf', $costing))->assertNotFound();

        // Am Event weder Preisliste noch Reiter Costing
        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->assertFormFieldDoesNotExist('price_list_id');
        $this->get(EventResource::getUrl('edit', ['record' => $this->event]))
            ->assertOk()
            ->assertDontSee('Konzert-Preisliste')
            ->assertDontSee('Costing');

        // Gespeichertes bleibt
        $this->assertSame($list->id, $this->event->fresh()->price_list_id);
        $this->assertSame(1, Costing::query()->count());
    }

    public function test_ohne_buchhaltung(): void
    {
        // Beginnt in 3 Tagen, Vertrag nicht zurück: mit Buchhaltung eine Finanz-Warnung
        $this->event->finance()->create(['contract_status' => 'angefragt']);
        $this->assertTrue($this->event->fresh()->hasFinanceAlert());
        $this->assertSame(25, EventProgress::phases($this->event->fresh())['buchung'], 'Titel, Datum, Vertrag: 3 von 12');

        $this->only(Module::StagePlan, Module::SeatingPlan, Module::GuestList);
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->assertTrue(Area::Buchhaltung->isDisabled());
        $this->get('/buchhaltung')->assertForbidden();
        $this->assertFalse($this->event->fresh()->hasFinanceAlert());
        $this->assertSame(20, EventProgress::phases($this->event->fresh())['buchung'], 'ohne Vertrag und Preisliste: 2 von 10');
        $this->get(EventResource::getUrl('edit', ['record' => $this->event]))
            ->assertOk()
            ->assertDontSee('FIBU-Status')
            ->assertDontSee('Zahlungsstatus fehlt');

        // Feldoptionen der Buchhaltung gibt es nicht, die Rechte der Rollen bleiben
        $this->assertNotContains(OptionField::AccountingStatus, OptionField::available());
        $this->assertContains(OptionField::Seating, OptionField::available());
        $this->get('/feldoptionen')->assertOk()->assertSee('Bestuhlung')->assertDontSee('FIBU-Status')->assertDontSee('Vertragsstatus');
        $role = $this->role('buchhaltung', ['buchhaltung' => 'edit']);
        $this->actingAs($this->systemAdmin())
            ->get(RoleResource::getUrl('edit', ['record' => $role]))
            ->assertOk()
            ->assertSee('Buchhaltung (Modul nicht freigegeben)');
        $this->assertSame('edit', $role->fresh()->permissions['buchhaltung']);
    }
}
