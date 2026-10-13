<?php

namespace Tests\Feature;

use App\Access\Area;
use App\Access\Module;
use App\Access\Modules;
use App\Enums\OptionField;
use App\Filament\Pages\ManageSystem;
use App\Filament\Pages\ManageVenue;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\SeatingPlans\SeatingPlanResource;
use App\Mail\DaysheetMail;
use App\Models\Event;
use App\Models\EventGuest;
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
            ->assertSchemaStateSet(['modules' => ['buehnenplan', 'saalplan', 'gaesteliste', 'buchhaltung']])
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
            ->assertSchemaStateSet(['modules' => ['buehnenplan', 'saalplan', 'gaesteliste']])
            ->fillForm(['modules' => ['buehnenplan', 'saalplan', 'gaesteliste', 'buchhaltung']])
            ->call('save');

        $this->assertTrue(Module::Accounting->isEnabled());
        $this->assertFalse(Area::Buchhaltung->isDisabled());
    }

    public function test_ohne_buehnenplan_bleiben_die_masse(): void
    {
        $this->only(Module::SeatingPlan, Module::GuestList, Module::Accounting);
        $this->actingAs($this->admin());

        $this->get(EventResource::getUrl('stage-plan', ['record' => $this->event]))->assertForbidden();
        $this->get(route('events.stage-plan-print', $this->event))->assertNotFound();
        $this->get(route('events.stage-plan-svg', $this->event))->assertNotFound();

        // Workspace: Planung › Bühne mit den Maßen, aber ohne Plan, Knopf und Versatz
        $this->get(EventResource::getUrl('edit', ['record' => $this->event]))
            ->assertOk()
            ->assertSee('Hauptbühne')
            ->assertDontSee('Bühnenplan');
        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->assertFormFieldExists('stage.width')
            ->assertFormFieldHidden('stage.wing_sl_offset');

        // Halle: Beschriftung und Raumbegrenzung gibt es nur für den Plan, sie bleiben beim Speichern
        Livewire::test(ManageVenue::class)
            ->assertDontSee('Raumbegrenzung')
            ->fillForm(['venue_name' => 'Inselpark Arena'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('IPA stage', StageSettings::load()->label);

        // Für Externe und im Daysheet: Bühne ja, Plan nein
        $sheet = ExternSheet::make($this->event->fresh(), fn (): string => '');
        $this->assertSame('14 × 8 m', $sheet['stage']['rows']['Hauptbühne']);
        $this->assertNull($sheet['stage']['svg']);
        $this->get('/daysheet/' . $this->daysheetToken())
            ->assertOk()
            ->assertSee('Hauptbühne')
            ->assertDontSee('Bühnenplan anzeigen')
            ->assertDontSee('<svg', false);
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
