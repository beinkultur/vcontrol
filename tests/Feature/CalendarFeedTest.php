<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageVenue;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use App\Models\Promoter;
use App\Models\Setting;
use App\Support\EventIcsFeed;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarFeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $promoter = Promoter::create(['name' => 'Konzertagentur Nord']);
        Event::create(['title' => 'Sommerkonzert', 'status' => 'bestätigt', 'promoter_id' => $promoter->id,
            'event_type1' => 'Konzert', 'event_type2' => 'Pop / Rock', 'starts_at' => '2026-10-09 00:00:00', 'ends_at' => '2026-10-10 00:00:00']);
        Event::create(['title' => 'Abgesagt', 'status' => 'storniert', 'starts_at' => '2026-10-11 00:00:00']);
        Event::create(['title' => 'Zwei Tage, Ende fehlt', 'status' => 'bestätigt', 'starts_at' => '2026-11-01 00:00:00']);
    }

    public function test_aus_ohne_schluessel(): void
    {
        $this->get('/kalender/events.ics')->assertNotFound();
        $this->get('/kalender/events.ics?token=irgendwas')->assertNotFound();
    }

    public function test_mit_schluessel_alle_bestaetigten_events(): void
    {
        Setting::put(Setting::CALENDAR_FEED_TOKEN, 'geheim-123');

        $this->get('/kalender/events.ics?token=falsch')->assertForbidden();
        $ics = $this->get('/kalender/events.ics?token=geheim-123')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->getContent();

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
        $ics = str_replace("\r\n ", '', $ics); // gefaltete Zeilen wieder zusammensetzen
        $this->assertStringContainsString("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("SUMMARY:Sommerkonzert\r\n", $ics);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261009\r\nDTEND;VALUE=DATE:20261010\r\n", $ics);
        // Ohne Ende: ganztägig, DTEND exklusiv am Folgetag
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261101\r\nDTEND;VALUE=DATE:20261102\r\n", $ics);
        $this->assertStringContainsString('DESCRIPTION:Veranstalter: Konzertagentur Nord\\nKategorie: Konzert / Pop / Rock', $ics);
        $this->assertStringNotContainsString('Abgesagt', $ics);
        $this->assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
    }

    public function test_angemeldet_mit_kalenderrecht_auch_ohne_schluessel(): void
    {
        Setting::put(Setting::CALENDAR_FEED_TOKEN, 'geheim-123');

        $this->actingAs($this->userWith($this->role('technik', ['kalender' => 'read'])));
        $this->get('/kalender/events.ics')->assertOk();

        $this->actingAs($this->userWith($this->role('buchhaltung', ['buchhaltung' => 'read'])));
        $this->get('/kalender/events.ics')->assertForbidden();

        // Gesperrtes Konto mit noch laufender Sitzung: kein Feed mehr
        $gesperrt = $this->userWith($this->role('technik2', ['kalender' => 'read']));
        $gesperrt->update(['is_active' => false]);
        $this->actingAs($gesperrt->fresh());
        $this->get('/kalender/events.ics')->assertForbidden();
    }

    public function test_steuerzeichen_im_titel_ergeben_keine_eigene_zeile(): void
    {
        Setting::put(Setting::CALENDAR_FEED_TOKEN, 'geheim-123');
        Event::create(['title' => "Konzert\rX-INJECT:1\x07", 'status' => 'bestätigt', 'starts_at' => '2026-12-01 00:00:00']);

        $ics = str_replace("\r\n ", '', $this->get('/kalender/events.ics?token=geheim-123')->assertOk()->getContent());

        $this->assertStringContainsString('SUMMARY:Konzert\\nX-INJECT:1' . "\r\n", $ics);
        $this->assertStringNotContainsString("\rX-INJECT", $ics);
        $this->assertStringNotContainsString("\x07", $ics);
    }

    public function test_adresse_auf_der_hallenseite_und_in_der_liste(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageVenue::class)
            ->assertSee('ausgeschaltet')
            ->callAction(TestAction::make('enableFeed')->schemaComponent('feedActions', 'content'));
        $this->assertNotNull(EventIcsFeed::token());
        $this->assertStringContainsString('/kalender/events.ics?token=' . EventIcsFeed::token(), (string) EventIcsFeed::url());

        Livewire::test(ListEvents::class)
            ->assertActionVisible('calendarFeed');

        // Ohne Recht auf den Kalender keine Adresse
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        Livewire::test(ListEvents::class)
            ->assertActionHidden('calendarFeed');

        $this->actingAs($this->admin());
        Livewire::test(ManageVenue::class)
            ->callAction(TestAction::make('disableFeed')->schemaComponent('feedActions', 'content'));
        $this->assertFalse(EventIcsFeed::isEnabled());
    }
}
