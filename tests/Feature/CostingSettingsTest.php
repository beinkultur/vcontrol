<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\AssignmentRole;
use App\Filament\Pages\ManageVenue;
use App\Filament\Resources\Costings\CostingResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\ExternEvents\ExternEventResource;
use App\Mail\DaysheetMail;
use App\Models\Costing;
use App\Models\Event;
use App\Models\PriceList;
use App\Models\Promoter;
use App\Models\Setting;
use App\Support\Costings;
use App\Support\CostingSettings;
use App\Support\Daysheets;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Costing-Einstellungen der Halle (Verwaltung › Halle): Rollen, Externe, Daysheet, Mail-Vorgaben. */
class CostingSettingsTest extends TestCase
{
    private Event $event;

    private Costing $costing;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $list = PriceList::create(['name' => 'Konzert-Preisliste 2025-09']);
        $list->items()->create(['section' => 'Mietkosten', 'name' => 'Grundmiete', 'unit' => 'pauschal', 'price' => 2800, 'default_factor' => 1]);
        $promoter = Promoter::create(['name' => 'Konzertagentur']);
        $promoter->contacts()->create(['first_name' => 'Pia', 'last_name' => 'Booking', 'email' => 'booking@agentur.de']);
        $this->event = Event::create(['title' => 'Konzert Nord', 'status' => 'bestätigt', 'starts_at' => now()->addDays(3), 'promoter_id' => $promoter->id]);
        $this->costing = Costing::createFor($this->event, $list);
    }

    /** @param  array<string, mixed>  $data */
    private function saveVenue(array $data): void
    {
        $this->actingAs($this->admin());
        Livewire::test(ManageVenue::class)
            ->fillForm(['venue_name' => 'Inselpark Arena', ...$data])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_admin_legt_fest_welche_rollen_costings_sehen(): void
    {
        $eventmanager = $this->role('eventmanager', ['events' => 'edit']);
        $buchhaltung = $this->role('buchhaltung', ['events' => 'read', 'buchhaltung' => 'edit']);
        $catering = $this->role('catering', ['events' => 'read']);

        // Vorgabe: wer Events bearbeitet oder die Buchhaltung sieht
        $this->assertSame([$eventmanager->id, $buchhaltung->id], CostingSettings::menuRoleIds());
        $this->assertSame([$eventmanager->id, $buchhaltung->id], CostingSettings::eventRoleIds());

        $this->saveVenue(['costing_menu_roles' => [$buchhaltung->id], 'costing_event_roles' => [$eventmanager->id, $catering->id]]);
        $this->assertSame((string) $buchhaltung->id, Setting::lookup(Setting::COSTING_MENU_ROLES));

        // Eventmanager: kein Menüpunkt, aber das Costing am Event – und darf es bearbeiten
        $this->actingAs($manager = $this->userWith($eventmanager));
        $this->get(CostingResource::getUrl())->assertForbidden();
        $this->assertFalse(CostingResource::shouldRegisterNavigation());
        $this->get(CostingResource::getUrl('edit', ['record' => $this->costing]))->assertOk();
        $this->get(EventResource::getUrl('edit', ['record' => $this->event]) . '?phase=buchung&bereich=costing')->assertOk()->assertSee('Fassung 1');
        $this->assertTrue($manager->can('update', $this->costing));

        // Catering: sieht es am Event, ändert nichts
        $this->actingAs($caterer = $this->userWith($catering));
        $this->get(CostingResource::getUrl('view', ['record' => $this->costing]))->assertOk();
        $this->get(route('costings.pdf', $this->costing))->assertOk();
        $this->assertFalse($caterer->can('update', $this->costing));
        $this->get(CostingResource::getUrl())->assertForbidden();

        // Buchhaltung: die Liste und darin jedes Costing
        $this->actingAs($this->userWith($buchhaltung));
        $this->get(CostingResource::getUrl())->assertOk()->assertSee('Konzert Nord');
        $this->assertTrue(CostingResource::shouldRegisterNavigation());
        $this->get(CostingResource::getUrl('view', ['record' => $this->costing]))->assertOk();

        // Admins sehen immer alles
        $this->actingAs($this->admin());
        $this->get(CostingResource::getUrl())->assertOk();
    }

    public function test_costing_fuer_externe_nur_wenn_freigegeben(): void
    {
        $this->costing->update(['sent_at' => now(), 'sent_to' => ['booking@agentur.de']]);
        $freelancer = $this->userWith($this->role('extern', ['events_extern' => 'read']));
        $freelancer->update(['account_type' => AccountType::Freelancer]);
        $this->event->assignments()->create(['role' => AssignmentRole::HouseRepEarly, 'assignee_type' => 'user', 'assignee_id' => $freelancer->id]);
        $view = ExternEventResource::getUrl('view', ['record' => $this->event]);

        $this->actingAs($freelancer->fresh());
        $this->get($view)->assertOk()->assertDontSee('Costing, Fassung 1');
        $this->get(route('events.costing', $this->event))->assertForbidden();

        $this->saveVenue(['costing_extern' => true]);
        $this->actingAs($freelancer->fresh());
        $this->get($view)->assertOk()->assertSee('Costing, Fassung 1 vom ' . now()->format('d.m.Y'))->assertSee(route('events.costing', $this->event), false);
        $this->get(route('events.costing', $this->event))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Nur die beteiligten Externen, und nur verschickte Fassungen
        $other = Event::create(['title' => 'Andere', 'starts_at' => now()->addDays(4)]);
        $this->get(route('events.costing', $other))->assertNotFound();
        $this->costing->update(['sent_at' => null]);
        $this->get(route('events.costing', $this->event))->assertNotFound();
    }

    public function test_costing_im_daysheet_nur_wenn_freigegeben(): void
    {
        Mail::fake();
        $this->costing->update(['sent_at' => now(), 'sent_to' => ['booking@agentur.de']]);
        $this->actingAs($this->admin());
        Daysheets::send($this->event, ['buero@halle.de'], [], 'Daysheet {event}', "Hallo,\n{link}");
        preg_match('#/daysheet/([A-Za-z0-9]{48})#', Mail::sent(DaysheetMail::class)->last()->text, $match);
        $token = $match[1];

        $this->get('/daysheet/' . $token)->assertOk()->assertDontSee('Costing');
        $this->get(route('daysheet.costing', $token))->assertNotFound();

        $this->saveVenue(['costing_daysheet' => true]);
        auth()->logout();
        $this->get('/daysheet/' . $token)->assertOk()->assertSee('Costing, Fassung 1')->assertSee(route('daysheet.costing', $token), false);
        $this->get(route('daysheet.costing', $token))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_mailvorgaben_unter_halle(): void
    {
        $this->saveVenue([
            'costing_subject' => 'Kalkulation {event} ({fassung})',
            'costing_text' => "Moin,\n\nhier die Kalkulation nach {preisliste}.",
            'costing_bcc' => ['buchhaltung@halle.de'],
        ]);

        $manager = $this->userWith($this->role('eventmanager', ['events' => 'edit']));
        $defaults = Costings::defaults($this->costing, $manager);
        $this->assertSame(['booking@agentur.de'], $defaults['to']);
        $this->assertSame(['buchhaltung@halle.de', mb_strtolower($manager->email)], $defaults['bcc']);
        $this->assertSame('Kalkulation Konzert Nord (Fassung 1)', $defaults['subject']);
        $this->assertSame("Moin,\n\nhier die Kalkulation nach Konzert-Preisliste 2025-09.", $defaults['body']);
    }
}
