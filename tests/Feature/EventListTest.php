<?php

namespace Tests\Feature;

use App\Enums\AssignmentRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Promoter;
use App\Models\Room;
use App\Support\EventDisplay;
use App\Support\ExecutionCheck;
use Filament\Facades\Filament;
use Filament\Tables\Enums\FiltersLayout;
use Livewire\Livewire;
use Tests\TestCase;

class EventListTest extends TestCase
{
    private Event $kommend;

    private Event $vergangenOffen;

    private Event $abgeschlossen;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($this->admin());

        $promoter = Promoter::create(['name' => 'Konzertagentur']);
        $this->kommend = Event::create(['title' => 'Konzert', 'promoter_id' => $promoter->id, 'status' => 'bestätigt', 'starts_at' => now()->addWeek()]);
        $this->vergangenOffen = Event::create(['title' => 'Messe', 'status' => 'bestätigt', 'starts_at' => now()->subWeek()]);
        $this->abgeschlossen = Event::create(['title' => 'Gala', 'status' => 'bestätigt', 'starts_at' => now()->addMonth(), 'closed' => true]);
    }

    /** Alles unter Durchführung › Check mit Wert, Bus-Strom 0 – auf Wunsch mit Backstage */
    private function complete(Event $event, bool $room = false): Event
    {
        $event->checklist()->create(['special_cleaning' => 'no', 'power_ant' => false, 'house_rig_early' => false, 'merch_fee' => 'keine', 'merch_fee_check' => 'na']);
        $event->operation()->create(['house_delay' => false, 'person_lift' => false, 'bus_power' => 0]);
        $event->stage()->create(['sold_out_award' => false]);
        if ($room) {
            $event->rooms()->attach(Room::create(['name' => 'Backstage ' . $event->id])->id, ['usage_type' => 'backstage']);
        }

        return $event->fresh();
    }

    public function test_voreingestellt_offen_und_ab_heute(): void
    {
        // Vergangen, Durchführung vollständig erfasst: nicht mehr unter „Zukünftige“
        $erledigt = $this->complete(Event::create(['title' => 'Erledigt', 'status' => 'bestätigt', 'starts_at' => now()->subDays(3)]));

        Livewire::test(ListEvents::class)
            // Vergangen, aber Durchführung unvollständig: bleibt stehen (seit 21.10.2026)
            ->assertCanSeeTableRecords([$this->kommend, $this->vergangenOffen])
            ->assertCanNotSeeTableRecords([$this->abgeschlossen, $erledigt]);
    }

    public function test_vergangene_bleiben_bis_die_durchfuehrung_erfasst_ist(): void
    {
        $konzert = $this->complete(Event::create(['title' => 'Konzert ohne Raum', 'event_type1' => 'Konzert', 'starts_at' => now()->subDays(2)]));
        $messe = $this->complete(Event::create(['title' => 'Messe ohne Raum', 'event_type1' => 'Messe', 'starts_at' => now()->subDays(2)]));
        $ohneLift = $this->complete(Event::create(['title' => 'Ohne Personenlift', 'starts_at' => now()->subDays(2)]));
        $ohneLift->operation->update(['person_lift' => null]);
        $ohneMerch = $this->complete(Event::create(['title' => 'Ohne Merch-Fee', 'starts_at' => now()->subDays(2)]));
        $ohneMerch->checklist->update(['merch_fee' => ' ']);
        $ohneStrom = $this->complete(Event::create(['title' => 'Ohne Bus-Strom', 'starts_at' => now()->subDays(2)]));
        $ohneStrom->operation->update(['bus_power' => null]);

        $component = Livewire::test(ListEvents::class)
            // Konzerte brauchen einen Raum (Backstage oder Büro), andere nicht
            ->assertCanSeeTableRecords([$konzert, $ohneLift, $ohneStrom])
            // Ein leerer Merch-Fee-Text zählt nicht (Vorgabe vom 21.10.2026)
            ->assertCanNotSeeTableRecords([$messe, $ohneMerch]);
        $this->assertSame(['Raum (Backstage oder Büro)'], ExecutionCheck::missing($konzert));
        $this->assertSame(['Personenlift'], ExecutionCheck::missing($ohneLift->fresh()));
        $this->assertSame([], ExecutionCheck::missing($ohneMerch->fresh()));
        $this->assertSame(['Bus-Strom'], ExecutionCheck::missing($ohneStrom->fresh()));
        $this->assertTrue(ExecutionCheck::isComplete($messe));

        $konzert->rooms()->attach(Room::create(['name' => 'Büro'])->id, ['usage_type' => 'office']);
        Livewire::test(ListEvents::class)->assertCanNotSeeTableRecords([$konzert]);

        // „Vergangene“ und „Alle“ wie bisher
        Livewire::test(ListEvents::class)->filterTable('time', ['time' => 'past'])->assertCanSeeTableRecords([$konzert, $messe]);
    }

    public function test_vergangene_offene_events(): void
    {
        Livewire::test(ListEvents::class)
            ->filterTable('time', ['time' => 'past'])
            ->assertCanSeeTableRecords([$this->vergangenOffen])
            ->assertCanNotSeeTableRecords([$this->kommend]);

        $this->assertTrue($this->vergangenOffen->isOverdue());
        $this->assertFalse($this->kommend->isOverdue());
    }

    public function test_status_abgeschlossen(): void
    {
        Livewire::test(ListEvents::class)
            ->filterTable('state', ['state' => 'closed'])
            ->assertCanSeeTableRecords([$this->abgeschlossen])
            ->assertCanNotSeeTableRecords([$this->kommend]);
    }

    public function test_suche_nach_titel(): void
    {
        Livewire::test(ListEvents::class)
            ->filterTable('state', ['state' => 'all'])
            ->filterTable('time', ['time' => 'all'])
            ->searchTable('Messe')
            ->assertCanSeeTableRecords([$this->vergangenOffen])
            ->assertCanNotSeeTableRecords([$this->kommend, $this->abgeschlossen]);
    }

    public function test_detailansicht(): void
    {
        $this->get('/events/' . $this->kommend->id)
            ->assertOk()
            ->assertSee('Konzert')
            ->assertSee('Konzertagentur');
    }

    public function test_spalten_wie_in_der_php_version(): void
    {
        $anna = Contact::create(['name' => 'Anna Leitung', 'initials' => 'AL']);
        $this->kommend->assignments()->create(['role' => AssignmentRole::ProjectLead, 'assignee_type' => 'contact', 'assignee_id' => $anna->id]);
        $this->kommend->update(['seating' => ['bestuhlt'], 'event_type1' => 'Konzert', 'event_type2' => 'Pop / Rock']);
        $fraglich = Event::create(['title' => 'Vielleicht', 'status' => 'fraglich', 'starts_at' => now()->addDays(10)]);

        Livewire::test(ListEvents::class)
            ->assertTableColumnStateSet('project_lead', 'AL', $this->kommend)
            ->assertTableColumnStateSet('seated', '🪑', $this->kommend)
            ->assertTableColumnStateSet('category', 'Pop / Rock', $this->kommend)
            ->assertTableColumnStateSet('promoter_short', 'Konzertagentur', $this->kommend)
            ->assertSee(EventDisplay::month($this->kommend->starts_at))
            ->assertSee('vc-row--fraglich')
            ->assertCanSeeTableRecords([$fraglich]);
    }

    public function test_auf_dem_handy_datum_status_und_veranstaltung(): void
    {
        $table = Livewire::test(ListEvents::class)->instance()->getTable();

        $onPhone = collect($table->getColumns())->filter(fn ($column): bool => $column->getVisibleFrom() === null)->keys()->all();
        $this->assertSame(['starts_at', 'status', 'title'], $onPhone);
        $this->assertSame('md', $table->getColumn('promoter_short')->getVisibleFrom());

        // Filter zu zweit nebeneinander statt untereinander, auf dem Handy hinter dem Filter-Symbol
        $this->assertSame(['default' => 2, 'lg' => 4], $table->getFiltersFormColumns());
        $this->assertSame(FiltersLayout::AboveContentCollapsible, $table->getFiltersLayout());
    }

    public function test_fortschritt_aller_drei_phasen(): void
    {
        Livewire::test(ListEvents::class)
            ->assertSeeHtml('vc-mini-progress__fill vc-fill--buchung')
            ->assertSeeHtml('vc-mini-progress__fill vc-fill--planung')
            ->assertSeeHtml('vc-mini-progress__fill vc-fill--durchfuehrung')
            ->assertSee('% · Durchführung')
            ->assertDontSeeHtml('vc-mini-progress__pct');
    }

    public function test_abgesagte_erst_mit_dem_schalter(): void
    {
        $storniert = Event::create(['title' => 'Storniertes Konzert', 'status' => Event::STATUS_CANCELLED, 'starts_at' => now()->addDays(3)]);
        $abgesagt = Event::create(['title' => 'Abgesagte Messe', 'status' => ' Abgesagt ', 'starts_at' => now()->addDays(4)]);
        $ohneStatus = Event::create(['title' => 'Ohne Status', 'starts_at' => now()->addDays(5)]);

        // Schalter in der Kopfzeile, nicht bei den Filtern (dort bleibt die Suche in ihrer Zeile)
        $this->get(EventResource::getUrl())
            ->assertOk()
            ->assertSee('Abgesagte Events anzeigen')
            ->assertSee('wire:click="toggleCancelled"', false)
            ->assertSee('aria-checked="false"', false);
        $component = Livewire::test(ListEvents::class)
            ->assertCanSeeTableRecords([$this->kommend, $ohneStatus])
            ->assertCanNotSeeTableRecords([$storniert, $abgesagt]);
        $this->assertSame(0, $component->instance()->getTable()->getActiveFiltersCount());
        $this->assertNull($component->instance()->getTable()->getFilter('cancelled'));

        $component->call('toggleCancelled')
            ->assertSet('showCancelled', true)
            ->assertCanSeeTableRecords([$this->kommend, $ohneStatus, $storniert, $abgesagt]);
        $component->call('toggleCancelled')
            ->assertCanNotSeeTableRecords([$storniert, $abgesagt]);

        // In der Adresse: Neuladen behält den Schalter
        Livewire::withQueryParams(['abgesagte' => '1'])->test(ListEvents::class)
            ->assertSet('showCancelled', true)
            ->assertCanSeeTableRecords([$storniert]);
        $this->get(EventResource::getUrl() . '?abgesagte=1')->assertSee('aria-checked="true"', false);

        $this->assertSame([$this->kommend->id, $ohneStatus->id], Event::query()->withoutCancelled()->whereIn('id', [$this->kommend->id, $ohneStatus->id, $storniert->id, $abgesagt->id])->orderBy('id')->pluck('id')->all());
    }

    public function test_filter_symbol_zaehlt_abweichungen_von_der_vorgabe(): void
    {
        $component = Livewire::test(ListEvents::class);
        $this->assertSame(0, $component->instance()->getTable()->getActiveFiltersCount(), 'offen und zukünftig sind die Vorgabe');

        $component->set('tableFilters.state.state', 'all')->set('tableFilters.time.time', 'past');
        $this->assertSame(2, $component->instance()->getTable()->getActiveFiltersCount());
    }

    public function test_klick_oeffnet_den_workspace(): void
    {
        $leser = $this->userWith($this->role('catering', ['events' => 'read']));

        Livewire::test(ListEvents::class)
            ->assertSee(EventResource::getUrl('edit', ['record' => $this->kommend]));
        $this->actingAs($leser);
        Livewire::test(ListEvents::class)
            ->assertSee(EventResource::getUrl('view', ['record' => $this->kommend]))
            ->assertDontSee(EventResource::getUrl('edit', ['record' => $this->kommend]));
    }

    public function test_leserecht_genuegt_fuer_die_liste(): void
    {
        $leser = $this->userWith($this->role('catering', ['events' => 'read']));

        $this->actingAs($leser)->get('/events')->assertOk();
    }
}
