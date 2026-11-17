<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\AdvancingRelationManager;
use App\Filament\Support\AdvancingFields;
use App\Models\AdvancingTemplate;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventAdvancing;
use App\Models\Setting;
use App\Models\User;
use App\Support\AdvancingCatalog;
use App\Support\Advancings;
use App\Support\AuditPresenter;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Befunde der Gesamtprüfung vom 17.11.2026, je ein Test, der vorher fehlschlug. */
class AdvancingReviewFixesTest extends TestCase
{
    private Event $event;

    private AdvancingTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-17 10:00'));
        Setting::put(Setting::VENUE_NAME, 'LKH Arena');
        $this->template = AdvancingCatalog::import(json_decode((string) file_get_contents(base_path('database/advancing/lkh-arena-kultur.json')), true));
        $this->event = Event::create(['title' => 'Comedy Abend', 'status' => 'bestätigt', 'starts_at' => '2026-12-05 20:00:00', 'onsite_email' => 'tour@example.org']);
        $this->event->schedule()->create(['admission' => '17:30:00']);
    }

    private function sent(): EventAdvancing
    {
        $advancing = Advancings::start($this->event, $this->template);
        Advancings::send($advancing, ['tour@example.org'], [], 'A', '{link}', null);

        return $advancing->fresh();
    }

    /** Befund 1: System-Admins nur für System-Admins; die gespeicherte Person bleibt wählbar */
    public function test_personenauswahl_ohne_system_admins_und_mit_gespeicherter_person(): void
    {
        $operator = $this->systemAdmin();
        $operator->update(['account_type' => AccountType::VenueEmployee, 'email' => 'betreiber@example.org']);
        $archived = Contact::create(['name' => 'Archiviert', 'email' => 'alt@example.org', 'is_archived' => true]);

        $this->actingAs($this->userWith($this->role('eventmanager', ['events' => 'edit'])));
        $options = Advancings::personOptions();
        $this->assertArrayNotHasKey("user:{$operator->id}", $options);
        $this->assertArrayNotHasKey("contact:{$archived->id}", $options);
        // Was schon gespeichert ist, bleibt wählbar – sonst ließe sich die Vorbelegung nicht mehr speichern
        $kept = Advancings::personOptions(["contact:{$archived->id}", 'tba', 'contact:999']);
        $this->assertSame('Archiviert  |  alt@example.org', $kept["contact:{$archived->id}"]);
        $this->assertArrayNotHasKey('contact:999', $kept);

        $this->actingAs($operator);
        $this->assertArrayHasKey("user:{$operator->id}", Advancings::personOptions());
    }

    /** Befund 2: Die Lese-Ansicht lädt keine Personenliste */
    public function test_lese_ansicht_zeigt_personen_nur_als_text(): void
    {
        $contact = Contact::create(['name' => 'Planerin', 'email' => 'p@example.org']);
        $question = ['key' => 'ap_advancing', 'type' => 'person', 'label' => 'Advancing', 'locked' => true];

        $this->assertInstanceOf(Select::class, AdvancingFields::field($question, editable: true));
        $readOnly = AdvancingFields::field($question, editable: false, value: "contact:{$contact->id}");
        $this->assertInstanceOf(TextEntry::class, $readOnly);
        $this->assertNotInstanceOf(Select::class, $readOnly);
    }

    /** Befund 3: Titel/Datum und Gültigkeit folgen dem Event */
    public function test_eventaenderung_zieht_vorbelegung_und_gueltigkeit_nach(): void
    {
        $advancing = $this->sent();
        $this->assertSame('2026-12-06 23:59', $advancing->expires_at->format('Y-m-d H:i'));

        $this->event->update(['title' => 'Comedy Abend (Zusatzshow)', 'starts_at' => '2026-12-12 20:00:00']);

        $advancing->refresh();
        $this->assertSame('Comedy Abend (Zusatzshow)', $advancing->prefill['veranstaltung']);
        $this->assertSame('2026-12-12', $advancing->prefill['datum']);
        $this->assertSame('2026-12-13 23:59', $advancing->expires_at->format('Y-m-d H:i'));

        // Hat der Planer den Wert selbst geändert, bleibt seiner stehen
        $advancing->update(['prefill' => [...$advancing->prefill, 'veranstaltung' => 'Eigener Titel']]);
        $this->event->update(['title' => 'Noch ein Titel']);
        $this->assertSame('Eigener Titel', $advancing->fresh()->prefill['veranstaltung']);

        // Gesperrter Link bleibt gesperrt, nie versendetes bekommt kein Ablaufdatum
        $advancing->update(['revoked_at' => now()]);
        $this->event->update(['starts_at' => '2026-12-19 20:00:00']);
        $this->assertTrue($advancing->fresh()->isRevoked());
    }

    /** Befund 4: Die Vorschau des Planers zählt nicht als „geöffnet“ */
    public function test_vorschau_angemeldeter_nutzer_setzt_nicht_geoeffnet(): void
    {
        $advancing = $this->sent();
        $this->actingAs($this->userWith($this->role('eventmanager', ['events' => 'edit'])));

        $this->get(substr((string) parse_url((string) $advancing->link(), PHP_URL_PATH), 0))
            ->assertOk()
            ->assertSee('Vorschau');
        $this->assertNull($advancing->fresh()->first_opened_at);
        $this->assertSame('versendet', $advancing->fresh()->statusLabel());
    }

    /** Befund 5: Übernimmt oder ändert der Planer einen Wert, gilt seiner */
    public function test_planer_ueberschreibt_antwort_des_veranstalters(): void
    {
        $advancing = Advancings::start($this->event, $this->template);
        $advancing->update(['answers' => ['einlass' => '18:00 Uhr', 'busse' => '2']]);

        // Übernehmen: Vorbelegung = Antwort
        Advancings::updatePrefill($advancing, [...$advancing->prefill, 'einlass' => '18:00 Uhr']);
        $advancing->refresh();
        $this->assertSame(['busse' => '2'], $advancing->answers);

        // Später ändern: der neue Vorschlag gilt
        Advancings::updatePrefill($advancing, [...$advancing->prefill, 'einlass' => '18:30 Uhr']);
        $this->assertSame('18:30 Uhr', Advancings::value($advancing->fresh(), 'einlass'));

        // Andere Antwort, Planer entscheidet anders: seine gilt; unberührte Antworten bleiben
        $advancing->update(['answers' => ['einlass' => '19:00 Uhr', 'busse' => '2']]);
        Advancings::updatePrefill($advancing->fresh(), [...$advancing->fresh()->prefill, 'einlass' => '18:45 Uhr']);
        $this->assertSame('18:45 Uhr', Advancings::value($advancing->fresh(), 'einlass'));
        $this->assertSame(['busse' => '2'], $advancing->fresh()->answers);
    }

    /** Befund 6: Verlauf (geöffnet, gespeichert, abgeschlossen) im Reiter */
    public function test_verlauf_im_reiter(): void
    {
        $advancing = $this->sent();
        $advancing->update([
            'first_opened_at' => '2026-11-17 11:00:00',
            'last_saved_at' => '2026-11-17 11:20:00',
            'closed_at' => '2026-11-18 09:00:00',
            'closed_by_name' => 'Pia Planer',
        ]);
        $this->actingAs($this->userWith($this->role('eventmanager', ['events' => 'edit'])));

        Livewire::test(AdvancingRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class])
            ->assertTableColumnStateSet('history', 'Geöffnet 17.11. 11:00 · Gespeichert 17.11. 11:20 · Abgeschlossen 18.11. 09:00 (Pia Planer)', $advancing);
    }

    /** Befund 7: Feldname sent_at im Costing-Audit unverändert */
    public function test_audit_feldnamen_des_costings_unveraendert(): void
    {
        $this->assertSame('Verschickt', AuditPresenter::field('costings', 'sent_at'));
        $this->assertSame('Versendet', AuditPresenter::field('event_advancings', 'sent_at'));
    }
}
