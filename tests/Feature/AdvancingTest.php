<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Mail\DaysheetMail;
use App\Models\AdvancingTemplate;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventAdvancing;
use App\Models\Setting;
use App\Models\User;
use App\Support\AdvancingCatalog;
use App\Support\Advancings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class AdvancingTest extends TestCase
{
    private Event $event;

    private AdvancingTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-17 10:00'));
        Setting::put(Setting::VENUE_NAME, 'LKH Arena');

        $this->template = AdvancingCatalog::import(json_decode((string) file_get_contents(base_path('database/advancing/lkh-arena-kultur.json')), true));
        $this->event = Event::create([
            'title' => 'Comedy Abend',
            'status' => 'bestätigt',
            'starts_at' => '2026-12-05 20:00:00',
            'onsite_first_name' => 'Vor',
            'onsite_last_name' => 'Name',
            'onsite_phone' => '0151 000',
            'onsite_email' => 'Tour@Example.org',
        ]);
        $this->event->schedule()->create(['get_in' => '11:30:00', 'admission' => '17:30:00']);
    }

    public function test_start_kopiert_katalog_und_belegt_aus_dem_event_vor(): void
    {
        $advancing = Advancings::start($this->event, $this->template);

        $this->assertSame('Kultur', $advancing->template_name);
        $this->assertSame($this->template->id, $advancing->advancing_template_id);
        $this->assertSame($this->template->structure, $advancing->structure);
        $this->assertStringContainsString('LKH Arena', (string) $advancing->intro);
        $this->assertSame([
            'veranstaltung' => 'Comedy Abend',
            'datum' => '2026-12-05',
            'tourleitung' => 'Vor Name',
            'tourleitung_email' => 'Tour@Example.org',
            'tourleitung_mobil' => '0151 000',
            'get_in' => '11:30 Uhr',
            'einlass' => '17:30 Uhr',
        ], $advancing->prefill);
        // Ohne Wert im Event keine Vorbelegung (Showbeginn, Curfew …)
        $this->assertArrayNotHasKey('showbeginn', $advancing->prefill);
        $this->assertSame('Entwurf', $advancing->statusLabel());
        $this->assertNull($advancing->link());
    }

    public function test_zweites_advancing_je_event_geht_nicht(): void
    {
        Advancings::start($this->event, $this->template);

        $this->expectException(InvalidArgumentException::class);
        Advancings::start($this->event->fresh(), $this->template);
    }

    public function test_personen_auswahl_und_anzeige(): void
    {
        $user = User::factory()->create(['first_name' => 'Pia', 'last_name' => 'Planer', 'email' => 'pia@example.org', 'phone' => '0170 111', 'account_type' => AccountType::VenueEmployee]);
        User::factory()->create(['first_name' => 'Gewerk', 'last_name' => 'Konto', 'account_type' => AccountType::TradeAccount]);
        User::factory()->create(['first_name' => 'Inaktiv', 'last_name' => 'Konto', 'account_type' => AccountType::VenueEmployee, 'is_active' => false]);
        $contact = Contact::create(['name' => 'Kontakt', 'email' => 'k@example.org', 'phone' => '0151 000']);
        Contact::create(['name' => 'Archiviert', 'is_archived' => true]);

        $options = Advancings::personOptions();
        $this->assertSame('tba.', array_key_first($options) === 'tba' ? $options['tba'] : null);
        $this->assertSame('Pia Planer  |  pia@example.org  |  0170 111', $options["user:{$user->id}"]);
        $this->assertSame('Kontakt  |  k@example.org  |  0151 000', $options["contact:{$contact->id}"]);
        $this->assertCount(3, $options);

        $this->assertSame('Kontakt  |  k@example.org  |  0151 000', Advancings::personLabel("contact:{$contact->id}"));
        $contact->update(['email' => null]);
        $this->assertSame('Kontakt  |  0151 000', Advancings::personLabel("contact:{$contact->id}"));
        $contact->delete();
        $this->assertSame('tba.', Advancings::personLabel("contact:{$contact->id}"));
        $this->assertSame('tba.', Advancings::personLabel('tba'));
        $this->assertSame('tba.', Advancings::personLabel(null));
        $this->assertSame('tba.', Advancings::personLabel('event:1'));
    }

    public function test_versand_mit_link_und_gueltigkeit(): void
    {
        $sender = User::factory()->create(['email' => 'planer@example.org']);
        $advancing = Advancings::start($this->event, $this->template);
        $defaults = Advancings::defaults($advancing);
        $this->assertSame(['tour@example.org'], $defaults['to']);
        $this->assertSame([], $defaults['bcc']);
        $this->assertSame('Advancing Comedy Abend – Sa, 05.12.2026', $defaults['subject']);
        $this->assertStringContainsString('{link}', $defaults['body']);

        Advancings::send($advancing, ['tour@example.org'], ['produktion@example.org'], $defaults['subject'], "Hallo,\nbitte ausfüllen.", $sender);

        $advancing->refresh();
        $this->assertSame('2026-12-06 23:59', $advancing->expires_at->format('Y-m-d H:i'));
        $this->assertSame(['tour@example.org'], $advancing->recipients_to);
        $this->assertSame(['produktion@example.org'], $advancing->recipients_bcc);
        $this->assertSame($sender->id, $advancing->sent_by);
        $this->assertSame('versendet', $advancing->statusLabel());
        $this->assertTrue($advancing->isLinkValid());
        $link = $advancing->link();
        $this->assertMatchesRegularExpression('#/advancing/[A-Za-z0-9]{48}$#', (string) $link);
        $this->assertSame(EventAdvancing::hashToken(substr((string) $link, -48)), $advancing->token_hash);
        // Der Schlüssel steht nicht im Klartext in der Datenbank
        $this->assertStringNotContainsString(substr((string) $link, -48), (string) $advancing->getRawOriginal('token'));

        Mail::assertSent(DaysheetMail::class, fn (DaysheetMail $mail): bool => $mail->hasTo('tour@example.org')
            && $mail->hasBcc('produktion@example.org')
            && $mail->buttonLabel === 'Fragenkatalog öffnen'
            && $mail->link === $link
            // {link} fehlte im Text – er steht dann am Ende
            && str_ends_with(trim($mail->text), $link));

        // Erneut senden: derselbe Link
        Advancings::send($advancing->fresh(), ['tour@example.org'], [], 'Erinnerung', 'Bitte: {link}', $sender);
        $this->assertSame($link, $advancing->fresh()->link());
        Mail::assertSent(DaysheetMail::class, 2);
    }

    public function test_ohne_empfaenger_ohne_datum_oder_vorbei_kein_versand(): void
    {
        $advancing = Advancings::start($this->event, $this->template);
        try {
            Advancings::send($advancing, ['kein-mail'], [], 'A', '{link}', null);
            $this->fail('Ohne gültigen Empfänger verschickt');
        } catch (InvalidArgumentException) {
        }

        $this->event->update(['starts_at' => null]);
        try {
            Advancings::send($advancing->fresh(), ['tour@example.org'], [], 'A', '{link}', null);
            $this->fail('Ohne Datum verschickt');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Datum', $exception->getMessage());
        }

        $this->event->update(['starts_at' => '2026-11-01 20:00:00']);
        try {
            Advancings::send($advancing->fresh(), ['tour@example.org'], [], 'A', '{link}', null);
            $this->fail('Nach der Veranstaltung verschickt');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('vorbei', $exception->getMessage());
        }

        Mail::assertNothingSent();
        $this->assertNull($advancing->fresh()->token_hash);
    }

    public function test_scheitert_die_mail_bleibt_nichts_gespeichert(): void
    {
        $advancing = Advancings::start($this->event, $this->template);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP weg'));

        try {
            Advancings::send($advancing, ['tour@example.org'], [], 'A', '{link}', null);
            $this->fail('Mailfehler verschluckt');
        } catch (RuntimeException) {
        }

        $advancing->refresh();
        $this->assertNull($advancing->token_hash);
        $this->assertNull($advancing->sent_at);
        $this->assertSame('Entwurf', $advancing->statusLabel());
    }

    public function test_geaenderte_schluessel_und_anzeige(): void
    {
        $advancing = Advancings::start($this->event, $this->template);
        $advancing->update(['answers' => ['einlass' => '18:00 Uhr', 'busse' => '2', 'get_in' => '11:30 Uhr']]);
        $advancing->refresh();

        // get_in steht gleich der Vorbelegung in den Antworten – zählt nicht als Änderung
        $this->assertSame(['einlass', 'busse'], Advancings::changedKeys($advancing));
        $this->assertSame('18:00 Uhr', Advancings::value($advancing, 'einlass'));
        $this->assertSame('11:30 Uhr', Advancings::value($advancing, 'get_in'));
        $this->assertNull(Advancings::value($advancing, 'pkw'));

        $questions = $advancing->questions();
        $this->assertSame('05.12.2026', Advancings::display($questions['datum'], '2026-12-05'));
        $this->assertSame('–', Advancings::display($questions['pkw'], null));
        $this->assertSame('–', Advancings::display($questions['pkw'], '  '));
        $this->assertSame('ja', Advancings::display(['type' => 'yes_no'], 'ja'));
        $this->assertSame('tba.', Advancings::display($questions['ap_advancing'], 'tba'));
        $this->assertSame('2', Advancings::display($questions['busse'], '2'));
    }
}
