<?php

namespace Tests\Feature;

use App\Enums\OptionField;
use App\Filament\Pages\ManageVenue;
use App\Filament\Resources\Calendars\Pages\ManageCalendars;
use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Filament\Resources\FieldOptions\Pages\ManageFieldOptions;
use App\Filament\Resources\InventoryCategories\Pages\ManageInventoryCategories;
use App\Filament\Resources\InventoryItems\Pages\ManageInventoryItems;
use App\Filament\Resources\Rooms\Pages\ManageRooms;
use App\Filament\Resources\Trades\Pages\ManageTrades;
use App\Models\Calendar;
use App\Models\Employee;
use App\Models\FieldOption;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Trade;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($this->admin());
    }

    public function test_raum_anlegen_landet_am_ende(): void
    {
        Room::create(['name' => 'Arena', 'sort_order' => 10]);

        Livewire::test(ManageRooms::class)
            ->callAction('create', ['name' => 'Foyer', 'is_active' => true])
            ->assertHasNoActionErrors();

        $this->assertSame(20, Room::where('name', 'Foyer')->value('sort_order'));
    }

    public function test_raumname_ist_eindeutig(): void
    {
        Room::create(['name' => 'Arena']);

        Livewire::test(ManageRooms::class)
            ->callAction('create', ['name' => 'Arena'])
            ->assertHasActionErrors(['name' => 'unique']);
    }

    public function test_raum_bearbeiten_und_loeschen(): void
    {
        $room = Room::create(['name' => 'Arena']);

        Livewire::test(ManageRooms::class)
            ->callTableAction('edit', $room, ['name' => 'Große Halle'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('delete', $room);

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    public function test_va_kategorie_2_braucht_kategorie_1(): void
    {
        FieldOption::create(['field_key' => 'va_type1', 'value' => 'Konzert']);

        // Je Aufruf eine frische Komponente: Nach abgelehnter Eingabe bleibt der Dialog offen
        Livewire::test(ManageFieldOptions::class)
            ->set('activeTab', 'va_type2')
            ->callAction('create', ['field_key' => 'va_type2', 'value' => 'Rock'])
            ->assertHasActionErrors(['parent_value' => 'required']);
        Livewire::test(ManageFieldOptions::class)
            ->set('activeTab', 'va_type2')
            ->callAction('create', ['field_key' => 'va_type2', 'value' => 'Rock', 'parent_value' => 'Konzert'])
            ->assertHasNoActionErrors();

        $option = FieldOption::where('value', 'Rock')->firstOrFail();
        $this->assertSame(OptionField::VaType2, $option->field_key);
        $this->assertSame('Konzert', $option->parent_value);
    }

    public function test_feldoption_eindeutig_je_feld(): void
    {
        FieldOption::create(['field_key' => 'seating', 'value' => 'bestuhlt']);

        Livewire::test(ManageFieldOptions::class)
            ->callAction('create', ['field_key' => 'seating', 'value' => 'bestuhlt'])
            ->assertHasActionErrors(['value' => 'unique']);
        Livewire::test(ManageFieldOptions::class)
            ->callAction('create', ['field_key' => 'areas', 'value' => 'bestuhlt'])
            ->assertHasNoActionErrors();
    }

    public function test_tab_fuellt_das_feld_vor(): void
    {
        $component = Livewire::test(ManageFieldOptions::class)
            ->set('activeTab', 'pr_status')
            ->mountAction('create');

        $state = $component->instance()->mountedActions[0]['data']['field_key'] ?? null;
        $this->assertContains($state, [OptionField::PrStatus, 'pr_status']);
    }

    public function test_gewerk_mit_freien_leistungsbereichen_und_archiv(): void
    {
        Livewire::test(ManageTrades::class)
            ->callAction('create', ['name' => 'Reinigungsfirma', 'categories' => ['Reinigung', 'Neuer Bereich']])
            ->assertHasNoActionErrors();

        $trade = Trade::where('name', 'Reinigungsfirma')->firstOrFail();
        $this->assertSame(['Reinigung', 'Neuer Bereich'], $trade->categories);

        Livewire::test(ManageTrades::class)
            ->callTableAction('edit', $trade, ['is_archived' => true])
            ->assertHasNoTableActionErrors()
            ->assertTableActionDoesNotExist('delete');

        $this->assertTrue($trade->fresh()->is_archived);
    }

    public function test_mitarbeiter_positionen_aus_feldoptionen(): void
    {
        FieldOption::create(['field_key' => 'employee_position', 'value' => 'VL']);
        FieldOption::create(['field_key' => 'employee_position', 'value' => 'VfV']);
        $employee = Employee::create(['last_name' => 'Muster', 'positions' => ['VL']]);

        Livewire::test(ManageEmployees::class)
            ->callTableAction('edit', $employee, ['positions' => ['VL', 'VfV']])
            ->assertHasNoTableActionErrors();

        $this->assertSame(['VL', 'VfV'], $employee->fresh()->positions);
    }

    public function test_inventar_kategorie_mit_artikeln_bleibt(): void
    {
        $voll = InventoryCategory::create(['name' => 'Technik']);
        $voll->items()->create(['name' => 'Beamer']);
        $leer = InventoryCategory::create(['name' => 'Leer']);

        Livewire::test(ManageInventoryCategories::class)
            ->assertTableActionHidden('delete', $voll)
            ->callTableAction('delete', $leer);

        $this->assertDatabaseHas('inventory_categories', ['id' => $voll->id]);
        $this->assertDatabaseMissing('inventory_categories', ['id' => $leer->id]);
    }

    public function test_inventar_artikel_anlegen_aber_nicht_loeschen(): void
    {
        $category = InventoryCategory::create(['name' => 'Technik']);

        Livewire::test(ManageInventoryItems::class)
            ->callAction('create', ['category_id' => $category->id, 'name' => 'Beamer', 'is_active' => true])
            ->assertHasNoActionErrors()
            ->assertTableActionDoesNotExist('delete');

        $this->assertSame($category->id, InventoryItem::where('name', 'Beamer')->value('category_id'));
    }

    public function test_system_kalender_nur_farbe_aenderbar_und_nicht_loeschbar(): void
    {
        $events = Calendar::create(['key' => 'events', 'name' => 'Events', 'color' => '#C54E4C', 'is_system' => true]);

        Livewire::test(ManageCalendars::class)
            ->assertTableActionHidden('delete', $events)
            ->callTableAction('edit', $events, ['name' => 'Umbenannt', 'color' => '#123456'])
            ->assertHasNoTableActionErrors();

        $events->refresh();
        $this->assertSame('Events', $events->name);
        $this->assertSame('#123456', $events->color);
    }

    public function test_kalender_ebene_anlegen_und_kennung_pruefen(): void
    {
        Livewire::test(ManageCalendars::class)
            ->callAction('create', ['key' => 'Mit Leerzeichen', 'name' => 'X', 'color' => '#00aa00'])
            ->assertHasActionErrors(['key' => 'regex']);
        Livewire::test(ManageCalendars::class)
            ->callAction('create', ['key' => 'technik', 'name' => 'Technik', 'color' => '#00aa00'])
            ->assertHasNoActionErrors();

        $this->assertFalse(Calendar::findOrFail('technik')->is_system);
    }

    public function test_hallenname_speichern_und_in_der_kopfzeile(): void
    {
        Livewire::test(ManageVenue::class)
            ->fillForm(['venue_name' => 'Inselpark Arena'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Inselpark Arena', Setting::lookup(Setting::VENUE_NAME));
        $this->assertSame('VenueControl · Inselpark Arena', Filament::getPanel('app')->getBrandName());
    }

    public function test_hallenname_ist_pflicht(): void
    {
        Livewire::test(ManageVenue::class)
            ->fillForm(['venue_name' => ''])
            ->call('save')
            ->assertHasFormErrors(['venue_name' => 'required']);
    }

    public function test_leserecht_sieht_aber_aendert_nicht(): void
    {
        Setting::put(Setting::VENUE_NAME, 'Inselpark Arena');
        $trade = Trade::create(['name' => 'Firma']);
        $leser = $this->userWith($this->role('leser', ['admin_gewerke' => 'read', 'admin_stammdaten' => 'read']));
        $this->actingAs($leser);

        Livewire::test(ManageTrades::class)
            ->assertActionHidden('create')
            ->assertTableActionHidden('edit', $trade);

        Livewire::test(ManageVenue::class)
            ->set('data.venue_name', 'Geändert')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Inselpark Arena', Setting::lookup(Setting::VENUE_NAME));
    }
}
