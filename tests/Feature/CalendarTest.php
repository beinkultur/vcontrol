<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\AssignmentRole;
use App\Filament\Pages\Calendar as CalendarPage;
use App\Filament\Resources\ExternEvents\ExternEventResource;
use App\Models\Calendar;
use App\Models\CalendarEntry;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Inquiry;
use App\Models\Promoter;
use App\Models\User;
use App\Support\CalendarItem;
use App\Support\CalendarItems;
use App\Support\CalendarLayout;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Kalender (Monat, Woche, Tag, 4 Wochen) mit Ebenen, Optionen, Diensten und eigenen Einträgen; Kalender für Externe. */
class CalendarTest extends TestCase
{
    private Event $concert;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->travelTo(Carbon::parse('2026-10-14 10:00'));

        foreach ([['personal', 'Persönlich', '#6366F1', 0], ['events', 'Events', '#C54E4C', 10], ['veranstaltungsleitung', 'Veranstaltungsleitung', '#0EA5E9', 15],
            ['anfragen', 'Anfragen', '#7E221B', 20], ['wartung', 'Wartung & Co.', '#5C2D91', 40], ['technik', 'Technik', '#FFD700', 50]] as [$key, $name, $color, $sort]) {
            Calendar::create(['key' => $key, 'name' => $name, 'color' => $color, 'is_system' => in_array($key, ['personal', 'events', 'anfragen'], true), 'sort_order' => $sort]);
        }

        $promoter = Promoter::create(['name' => 'Konzertagentur Nord', 'short_name' => 'KAN']);
        $this->concert = Event::create(['title' => 'Rock im Herbst', 'promoter_id' => $promoter->id, 'status' => 'bestätigt', 'starts_at' => '2026-10-24 20:00:00']);
        Event::create(['title' => 'Verbrauchermesse', 'status' => 'bestätigt', 'starts_at' => '2026-10-16 00:00:00', 'ends_at' => '2026-10-19 00:00:00']);
        Event::create(['title' => 'Comedy fraglich', 'status' => 'fraglich', 'starts_at' => '2026-10-21 00:00:00']);
        Event::create(['title' => 'Abgesagtes Konzert', 'status' => 'storniert', 'starts_at' => '2026-10-22 00:00:00']);

        $inquiry = Inquiry::create(['title' => 'Winter Rave', 'promoter_id' => $promoter->id, 'status' => Inquiry::OPEN]);
        $inquiry->dates()->create(['option_date' => '2026-10-28']);
        CalendarEntry::create(['calendar_key' => 'wartung', 'title' => 'Wartung Lüftung', 'starts_at' => '2026-10-20 00:00:00', 'ends_at' => '2026-10-21 23:59:59', 'all_day' => true]);
    }

    private function reader(array $calendars): User
    {
        return $this->userWith($this->role('team-' . implode('-', array_keys($calendars)), ['kalender' => 'read', 'events' => 'read'], calendars: $calendars));
    }

    public function test_monat_zeigt_events_optionen_und_eintraege_in_den_farben_der_ebenen(): void
    {
        $this->actingAs($this->reader(['events' => 'read', 'anfragen' => 'read', 'wartung' => 'read']));

        $this->get('/kalender?view=month&date=2026-10-01')
            ->assertOk()
            ->assertSee('Oktober 2026')
            ->assertSee('20:00 Rock im Herbst')
            ->assertSee('Verbrauchermesse')
            ->assertSee('1️⃣ Winter Rave (1. Opt.)')
            ->assertSee('Wartung Lüftung')
            ->assertSee('--vc-cal-color: #C54E4C', false)
            ->assertSee('is-fraglich', false)
            ->assertSee('is-cancelled', false);

        // Mehrtägig: ein Balken über drei Tage (Ende exklusiv gespeichert)
        $items = CalendarItems::for(auth()->user(), CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
        $fair = collect($items)->firstWhere('title', 'Verbrauchermesse');
        $this->assertSame('2026-10-18', $fair->lastDay->toDateString());
        $this->assertSame('2026-10-21', collect($items)->firstWhere('title', 'Wartung Lüftung')->lastDay->toDateString(), 'Einträge: Ende einschließlich');
    }

    public function test_events_zuerst_und_hoechstens_vier_bahnen_je_woche(): void
    {
        foreach (range(1, 5) as $i) {
            CalendarEntry::create(['calendar_key' => 'wartung', 'title' => "Prüfung {$i}", 'starts_at' => '2026-10-24 00:00:00', 'all_day' => true]);
        }
        $this->actingAs($this->reader(['events' => 'read', 'wartung' => 'read']));

        $items = CalendarItems::for(auth()->user(), CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-25'));
        $this->assertSame('Rock im Herbst', CalendarLayout::day(CarbonImmutable::parse('2026-10-24'), $items)[0]->title, 'das Event vor den ganztägigen Einträgen');

        // Am 24.10.: Konzert und drei Prüfungen in den vier Bahnen, zwei Prüfungen
        // dahinter; die Liste fürs Handy (im selben HTML) zeigt alle
        $html = $this->get('/kalender?view=month&date=2026-10-01')
            ->assertOk()
            ->assertSee('+2 weitere')
            ->assertSee('/kalender?view=day&amp;date=2026-10-24', false)
            ->getContent();
        $this->assertSame(2, substr_count($html, '>Prüfung 3<'), 'Raster und Liste');
        $this->assertSame(1, substr_count($html, '>Prüfung 5<'), 'nur die Liste');
        // Woche und Tag zeigen alles
        $this->get('/kalender?view=day&date=2026-10-24')->assertOk()->assertSee('Prüfung 5')->assertDontSee('vc-cal__more', false);
    }

    public function test_alle_ansichten(): void
    {
        $this->actingAs($this->reader(['events' => 'read']));

        $this->get('/kalender?view=week&date=2026-10-21')->assertOk()->assertSee('19. – 25. Oktober 2026')->assertSee('Rock im Herbst')->assertSee('Ganztägig');
        $this->get('/kalender?view=day&date=2026-10-24')->assertOk()->assertSee('Samstag, 24. Oktober 2026')->assertSee('20:00 Rock im Herbst');
        $this->get('/kalender?view=4weeks&date=2026-10-14')->assertOk()->assertSee('12. Oktober – 8. November 2026')->assertSee('Rock im Herbst');
        // Ungültiges Datum: heute
        $this->get('/kalender?view=month&date=2026-13-45')->assertOk()->assertSee('Oktober 2026');
    }

    public function test_nur_lesbare_ebenen_und_filter_in_der_adresse(): void
    {
        $this->actingAs($this->reader(['events' => 'read']));

        $this->get('/kalender?view=month&date=2026-10-01')
            ->assertOk()
            ->assertSee('Rock im Herbst')
            ->assertDontSee('Winter Rave')
            ->assertDontSee('Wartung Lüftung');

        $this->actingAs($this->reader(['events' => 'read', 'wartung' => 'read']));
        $this->get('/kalender?view=month&date=2026-10-01&cal[]=wartung')
            ->assertOk()
            ->assertSee('Wartung Lüftung')
            ->assertDontSee('Rock im Herbst');
    }

    public function test_ohne_recht_kein_kalender(): void
    {
        $this->actingAs($this->userWith($this->role('nur-events', ['events' => 'read'])));

        $this->get('/kalender')->assertForbidden();
    }

    public function test_eigene_eintraege_nur_auf_ebenen_mit_schreibrecht(): void
    {
        $writer = $this->userWith($this->role('wartung', ['kalender' => 'read'], calendars: ['wartung' => 'edit', 'technik' => 'read']));
        $this->actingAs($writer);

        Livewire::test(CalendarPage::class)
            ->callAction('createEntry', data: ['calendar_key' => 'wartung', 'title' => '  Prüfung   Rigging ', 'all_day' => false,
                'start_date' => '2026-10-29', 'start_time' => '08:00', 'end_date' => null, 'end_time' => '12:00'])
            ->assertHasNoActionErrors();
        $entry = CalendarEntry::query()->where('title', 'Prüfung Rigging')->sole();
        $this->assertSame('2026-10-29 08:00', $entry->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-29 12:00', $entry->ends_at->format('Y-m-d H:i'));
        $this->assertSame($writer->getFilamentName(), $entry->created_by_name);

        // Nur Ebenen mit Schreibrecht – nicht Technik (nur lesen), nicht Events
        Livewire::test(CalendarPage::class)
            ->callAction('createEntry', data: ['calendar_key' => 'technik', 'title' => 'Fremd', 'all_day' => true, 'start_date' => '2026-10-29'])
            ->assertHasActionErrors(['calendar_key']);

        // Ende vor Beginn
        Livewire::test(CalendarPage::class)
            ->callAction('createEntry', data: ['calendar_key' => 'wartung', 'title' => 'Rückwärts', 'all_day' => false,
                'start_date' => '2026-10-29', 'start_time' => '12:00', 'end_time' => '08:00']);
        $this->assertNull(CalendarEntry::query()->where('title', 'Rückwärts')->first());

        // Bearbeiten und löschen
        Livewire::test(CalendarPage::class)
            ->callAction('entry', data: ['calendar_key' => 'wartung', 'title' => 'Prüfung Rigging-Punkte', 'all_day' => true, 'start_date' => '2026-10-30'], arguments: ['id' => $entry->id])
            ->assertHasActionErrors(['end_date' => 'Das Ende liegt vor dem Beginn.']);
        Livewire::test(CalendarPage::class)
            ->callAction('entry', data: ['calendar_key' => 'wartung', 'title' => 'Prüfung Rigging-Punkte', 'all_day' => true, 'start_date' => '2026-10-30', 'end_date' => '2026-10-31'], arguments: ['id' => $entry->id])
            ->assertHasNoActionErrors();
        $entry->refresh();
        $this->assertSame('Prüfung Rigging-Punkte', $entry->title);
        $this->assertTrue($entry->all_day);
        $this->assertSame('2026-10-30 00:00:00 – 2026-10-31 23:59:59', $entry->starts_at->format('Y-m-d H:i:s') . ' – ' . $entry->ends_at->format('Y-m-d H:i:s'));

        // Löschen über den Knopf im Dialog
        Livewire::test(CalendarPage::class)
            ->callAction([TestAction::make('entry')->arguments(['id' => $entry->id]), TestAction::make('deleteEntry')]);
        $this->assertNull($entry->fresh());
        $entry = CalendarEntry::query()->where('title', 'Wartung Lüftung')->sole();

        // Nur lesen: kein Anlegen, der Eintrag öffnet sich zum Ansehen
        $this->actingAs($this->reader(['wartung' => 'read']));
        Livewire::test(CalendarPage::class)->assertActionHidden('createEntry');
        $this->assertFalse(auth()->user()->can('update', $entry->fresh()));
        $this->get('/kalender?entry=' . $entry->id)->assertOk();
    }

    public function test_dienste_auf_technik_veranstaltungsleitung_und_persoenlich(): void
    {
        $user = $this->userWith($this->role('team', ['kalender' => 'read', 'events' => 'read'], calendars: ['technik' => 'read', 'veranstaltungsleitung' => 'read']));
        $user->update(['first_name' => 'Anna', 'last_name' => 'Koch', 'account_type' => AccountType::VenueEmployee, 'positions' => ['VL']]);
        $user = $user->fresh();
        $tom = Contact::create(['name' => 'Tom Meyer', 'positions' => ['VfV']]);
        $early = $this->concert->assignments()->create(['role' => AssignmentRole::HouseRepEarly, 'assignee_type' => 'user', 'assignee_id' => $user->id, 'starts_at' => '08:00', 'ends_at' => '16:00']);
        $late = $this->concert->assignments()->create(['role' => AssignmentRole::SafetyLate, 'assignee_type' => 'contact', 'assignee_id' => $tom->id, 'starts_at' => '16:00', 'ends_at' => '01:00']);
        $this->concert->assignments()->create(['role' => AssignmentRole::ProjectLead, 'assignee_type' => 'user', 'assignee_id' => $user->id]);
        $items = collect(CalendarItems::for($user, CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-25')));

        // Der eigene Dienst einmal, auf Veranstaltungsleitung und Persönlich; die Projektleitung zählt nicht
        $own = $items->where('uid', 'role-' . $early->id);
        $this->assertCount(1, $own);
        $this->assertSame('🔗 Anna Koch · House Rep. früh (Rock im Herbst)', $own->first()->title);
        $this->assertSame(['veranstaltungsleitung', 'personal'], $own->first()->colorLayers());
        // Fremder Dienst nur auf Technik; Ende nach Mitternacht: bis 24 Uhr
        $other = $items->firstWhere('uid', 'role-' . $late->id);
        $this->assertSame('🔗 Tom Meyer · VfV spät (Rock im Herbst)', $other->title);
        $this->assertSame(['technik'], $other->colorLayers());
        $this->assertSame([960, 1440], $other->minutes());
        $this->assertCount(2, $items->where('kind', CalendarItem::DUTY));

        // Im Raster gestreift in beiden Farben
        $this->actingAs($user);
        $this->get('/kalender?view=week&date=2026-10-24')
            ->assertOk()
            ->assertSee('is-shared', false)
            ->assertSee('--vc-cal-color: #0EA5E9; --vc-cal-color-2: #6366F1', false);
        // Nur „Persönlich“ eingeblendet: einfarbig
        $mine = collect(CalendarItems::for($user, CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-25'), ['personal']));
        $this->assertSame([['personal']], $mine->map->colorLayers()->values()->all());
    }

    public function test_ebenen_in_fester_reihenfolge_und_standard_ansicht_aus_dem_profil(): void
    {
        Calendar::create(['key' => 'aaa', 'name' => 'Abbau', 'color' => '#111111', 'sort_order' => 99]);
        $user = $this->reader(['events' => 'read', 'anfragen' => 'read', 'wartung' => 'read', 'technik' => 'read', 'veranstaltungsleitung' => 'read', 'aaa' => 'read']);

        // Events, Anfragen, Persönlich, dann nach Namen – die Reihenfolge von Hand zählt nicht mehr
        $this->assertSame(['events', 'anfragen', 'personal', 'aaa', 'technik', 'veranstaltungsleitung', 'wartung'],
            CalendarItems::readableLayers($user)->pluck('key')->all());

        $this->actingAs($user);
        $this->get('/kalender')->assertOk()->assertSee('Oktober 2026')->assertSee('vc-cal--month', false);
        $user->update(['calendar_view' => 'week']);
        $this->get('/kalender')->assertOk()->assertSee('12. – 18. Oktober 2026')->assertSee('vc-cal--week', false);
        // Die Adresse geht vor
        $this->get('/kalender?view=day')->assertOk()->assertSee('Mittwoch, 14. Oktober 2026');
    }

    public function test_extern_nur_lesen_links_nur_zu_eigenen_events(): void
    {
        $trade = \App\Models\Trade::create(['name' => 'Lichtwerk']);
        $this->concert->services()->create(['service' => \App\Enums\ServiceCode::Vt, 'trade_id' => $trade->id]);
        $extern = $this->userWith($this->role('extern', ['events_extern' => 'read', 'kalender_extern' => 'read'], calendars: ['events' => 'read']));
        $extern->update(['account_type' => AccountType::TradeAccount, 'trade_id' => $trade->id]);
        $this->actingAs($extern->fresh());

        $this->get('/kalender')->assertForbidden();
        $this->get('/extern/kalender?view=month&date=2026-10-01')
            ->assertOk()
            ->assertSee('Kalender (extern)')
            ->assertSee(ExternEventResource::getUrl('view', ['record' => $this->concert->id]), false)
            ->assertSee('is-linked', false)
            ->assertSee('is-unlinked', false)
            ->assertDontSee('Winter Rave');
    }
}
