<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\PriceLists\Pages\CreatePriceList;
use App\Filament\Resources\PriceLists\Pages\EditPriceList;
use App\Filament\Resources\PriceLists\Pages\ListPriceLists;
use App\Filament\Resources\PriceLists\PriceListResource;
use App\Models\Event;
use App\Models\FieldOption;
use App\Models\PriceList;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use Tests\TestCase;

/** Preislisten der Halle (Modul Preislisten & Costing): pflegen, lesen, am Event wählen. */
class PriceListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    private function priceList(string $name = 'Konzert-Preisliste 2025-09', bool $active = true): PriceList
    {
        $list = PriceList::create(['name' => $name, 'valid_from' => '2025-09-01', 'is_active' => $active]);
        $list->items()->create(['section' => 'Mietkosten', 'name' => 'Grundmiete', 'unit' => 'pauschal', 'price' => 2800, 'default_factor' => 1, 'sort_order' => 1]);
        $list->items()->create(['section' => 'Nebenkosten', 'name' => 'Facility-Fee', 'unit' => 'pro verkauftem Ticket', 'price' => 1.5, 'minimum' => 2500, 'factor_from_pax' => true, 'sort_order' => 2]);

        return $list;
    }

    public function test_admin_legt_preisliste_mit_kostenpunkten_an(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $this->actingAs($this->admin());

        Livewire::test(CreatePriceList::class)
            ->fillForm([
                'name' => 'Konzert-Preisliste 2025-09',
                'valid_from' => '2025-09-01',
                'intro' => 'Maximalkapazität: 4.000 pax',
                'notes' => 'Alle Preise zzgl. MwSt.',
                'is_active' => true,
                'items' => [
                    ['section' => 'Mietkosten', 'name' => 'Grundmiete', 'description' => null, 'unit' => 'pauschal', 'price' => '2800', 'minimum' => null, 'default_factor' => '1', 'factor_from_pax' => false, 'default_active' => true],
                    ['section' => 'Nebenkosten', 'name' => 'Facility-Fee', 'description' => 'inkl. Reinigung', 'unit' => 'pro verkauftem Ticket', 'price' => '1.50', 'minimum' => '2500', 'default_factor' => null, 'factor_from_pax' => true],
                    ['section' => 'Weitere Kosten', 'name' => 'Sicherheitsdienst', 'description' => null, 'unit' => null, 'price' => null, 'minimum' => null, 'default_factor' => '1', 'factor_from_pax' => false, 'default_active' => false],
                    // So, wie die Maske es schreibt: Tausenderpunkt, Komma
                    ['section' => 'Bestuhlung', 'name' => 'Vollbestuhlung', 'description' => null, 'unit' => 'pauschal', 'price' => '3.800,50', 'minimum' => null, 'default_factor' => null, 'factor_from_pax' => false],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $undoRepeaterFake();

        $list = PriceList::query()->sole();
        $this->assertSame('2025-09-01', $list->valid_from->toDateString());
        $this->assertSame(['Grundmiete', 'Facility-Fee', 'Sicherheitsdienst', 'Vollbestuhlung'], $list->items->pluck('name')->all());
        $this->assertSame([1, 2, 3, 4], $list->items->pluck('sort_order')->all());
        $this->assertSame(3800.5, $list->items[3]->price);
        $fee = $list->items[1];
        $this->assertSame(1.5, $fee->price);
        $this->assertSame(2500.0, $fee->minimum);
        $this->assertTrue($fee->factor_from_pax);
        $this->assertNull($list->items[2]->price, 'ohne Preis: nach Aufwand');
        $this->assertFalse($list->items[2]->default_active, 'startet im neuen Costing ausgeschaltet');
        $this->assertTrue($list->items[0]->default_active);
    }

    public function test_preise_mit_tausenderpunkt_einheiten_aus_den_feldoptionen(): void
    {
        FieldOption::create(['field_key' => 'cost_unit', 'value' => 'pro Tag']);
        $list = $this->priceList();
        $rent = $list->items[0];
        $this->actingAs($this->admin());

        Livewire::test(EditPriceList::class, ['record' => $list->getRouteKey()])
            ->assertSchemaStateSet(['items.record-' . $rent->id . '.price' => '2.800,00', 'items.record-' . $list->items[1]->id . '.minimum' => '2.500,00'])
            ->assertFormFieldExists('items.record-' . $rent->id . '.unit', fn (Select $field): bool => array_key_exists('pro Tag', $field->getOptions())
                && array_key_exists('%', $field->getOptions()))
            ->set('data.items.record-' . $rent->id . '.price', '12.345,67')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(12345.67, $rent->fresh()->price);

        // Keine Zahl: Fehler statt still 0
        Livewire::test(EditPriceList::class, ['record' => $list->getRouteKey()])
            ->set('data.items.record-' . $rent->id . '.price', 'viel')
            ->call('save')
            ->assertHasFormErrors(['items.record-' . $rent->id . '.price']);
    }

    public function test_lesen_duerfen_buchhaltung_und_eventmanager_pflegen_nur_admins(): void
    {
        $list = $this->priceList();

        $eventmanager = $this->userWith($this->role('eventmanager', ['events' => 'edit']));
        $this->actingAs($eventmanager)->get(PriceListResource::getUrl())->assertOk();
        $this->get(PriceListResource::getUrl('view', ['record' => $list]))->assertOk()->assertSee('Facility-Fee');
        $this->get(PriceListResource::getUrl('create'))->assertForbidden();
        $this->get(PriceListResource::getUrl('edit', ['record' => $list]))->assertForbidden();

        $this->actingAs($this->userWith($this->role('buchhaltung', ['events' => 'read', 'buchhaltung' => 'edit'])));
        $this->get(PriceListResource::getUrl('view', ['record' => $list]))->assertOk();
        $this->get(PriceListResource::getUrl('edit', ['record' => $list]))->assertForbidden();

        // Nur lesen ohne Buchhaltung: nichts
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        $this->get(PriceListResource::getUrl())->assertForbidden();
    }

    public function test_kopie_als_grundlage_der_naechsten_preisliste(): void
    {
        $list = $this->priceList();
        $this->actingAs($this->admin());

        Livewire::test(ListPriceLists::class)
            ->callTableAction('copy', $list, data: ['name' => 'Konzert-Preisliste 2026-09'])
            ->assertHasNoTableActionErrors();

        $copy = PriceList::query()->where('name', 'Konzert-Preisliste 2026-09')->sole();
        $this->assertFalse($copy->is_active, 'inaktiv, bis sie fertig ist');
        $this->assertSame(['Grundmiete', 'Facility-Fee'], $copy->items->pluck('name')->all());
        $this->assertSame(2, $list->items()->count(), 'das Original bleibt');
    }

    public function test_genutzte_preisliste_wird_nicht_geloescht(): void
    {
        $list = $this->priceList();
        $admin = $this->admin();
        $this->assertTrue($admin->can('delete', $list));

        Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek(), 'price_list_id' => $list->id]);
        $this->assertFalse($admin->can('delete', $list->fresh()));
    }

    public function test_preisliste_beim_anlegen_des_events(): void
    {
        $list = $this->priceList();
        $old = $this->priceList('Konzert 2023', active: false);
        $this->actingAs($this->userWith($this->role('eventmanager', ['events' => 'edit'])));

        Livewire::test(CreateEvent::class)
            ->assertFormFieldExists('price_list_id', fn ($field): bool => array_key_exists($list->id, $field->getOptions())
                && !array_key_exists($old->id, $field->getOptions()))
            ->fillForm(['title' => 'Konzert Nord', 'starts_at' => now()->addMonth()->setTime(20, 0), 'price_list_id' => $list->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $event = Event::query()->where('title', 'Konzert Nord')->sole();
        $this->assertSame($list->id, $event->price_list_id);
        $this->assertSame('Konzert-Preisliste 2025-09', $event->priceList->name);

        // Eine inzwischen inaktive Preisliste bleibt am Event wählbar
        $event->update(['price_list_id' => $old->id]);
        $this->assertArrayHasKey($old->id, PriceList::choices($event->price_list_id));
    }
}
