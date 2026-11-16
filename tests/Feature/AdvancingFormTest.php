<?php

namespace Tests\Feature;

use App\Mail\AdvancingSubmittedMail;
use App\Mail\DaysheetMail;
use App\Models\AdvancingTemplate;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventAdvancing;
use App\Models\Promoter;
use App\Models\Setting;
use App\Models\User;
use App\Support\AdvancingCatalog;
use App\Support\Advancings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdvancingFormTest extends TestCase
{
    private Event $event;

    private EventAdvancing $advancing;

    private User $planer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-17 10:00'));
        Setting::put(Setting::VENUE_NAME, 'LKH Arena');
        Setting::put(Setting::MAIL_REPLY_TO, 'veranstaltungen@example.org');

        $template = AdvancingCatalog::import(json_decode((string) file_get_contents(base_path('database/advancing/lkh-arena-kultur.json')), true));
        $promoter = Promoter::create(['name' => 'Tourfirma', 'customer_no' => 'K-GEHEIM']);
        $this->event = Event::create([
            'title' => 'Comedy Abend',
            'status' => 'bestätigt',
            'starts_at' => '2026-12-05 20:00:00',
            'promoter_id' => $promoter->id,
            'booking_notes' => 'GEHEIM-NOTIZ',
            'wlan_password' => 'GEHEIM-WLAN',
            'description' => 'GEHEIM-BESCHREIBUNG',
            'onsite_first_name' => 'Vor',
            'onsite_last_name' => 'Name',
            'onsite_email' => 'tour@example.org',
        ]);
        $this->event->schedule()->create(['get_in' => '11:30:00']);
        $this->planer = User::factory()->create(['email' => 'planer@example.org']);
        $this->advancing = Advancings::start($this->event, $template);
        $this->token = $this->send($this->advancing);
    }

    private function send(EventAdvancing $advancing): string
    {
        Advancings::send($advancing, ['tour@example.org'], [], 'Advancing', '{link}', $this->planer);

        return substr((string) $advancing->fresh()->link(), -48);
    }

    /**
     * @param  array<string, string>  $answers  key => neuer Wert
     * @param  array<string, string>  $shown  key => Wert beim Laden (Vorgabe: aktueller Wert)
     */
    private function answer(array $answers, string $action = 'speichern', ?array $shown = null, ?string $token = null): TestResponse
    {
        $shown ??= collect($answers)->mapWithKeys(fn ($value, string $key): array => [$key => (string) Advancings::value($this->advancing->fresh(), $key)])->all();

        return $this->post('/advancing/' . ($token ?? $this->token), ['aktion' => $action, 'a' => $answers, 'o' => $shown]);
    }

    public function test_seite_zeigt_vorbelegung_und_venue(): void
    {
        $person = Contact::create(['name' => 'Technik Kontakt', 'email' => 'technik@example.org', 'phone' => '0151 222']);
        $this->advancing->update(['prefill' => [...$this->advancing->prefill, 'ap_technik' => "contact:{$person->id}"]]);

        $response = $this->get("/advancing/{$this->token}");

        $response->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('LKH Arena')
            ->assertSee('Comedy Abend')
            ->assertSee('Sa, 05.12.2026')
            ->assertSee('value="11:30 Uhr"', false)
            ->assertSee('name="a[busse]"', false)
            ->assertSee('name="_token"', false)
            // Gesperrt: als Text, nicht als Feld
            ->assertSee('Technik Kontakt  |  technik@example.org  |  0151 222')
            ->assertDontSee('name="a[ap_technik]"', false)
            ->assertDontSee('name="a[veranstaltung]"', false)
            ->assertSee('Zwischenspeichern')
            ->assertSee('Absenden');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_seite_zeigt_keine_fremden_event_daten(): void
    {
        Contact::create(['name' => 'Interner Kontakt', 'email' => 'intern@example.org']);
        $html = $this->get("/advancing/{$this->token}")->assertOk()->getContent();

        foreach (['GEHEIM-NOTIZ', 'GEHEIM-WLAN', 'GEHEIM-BESCHREIBUNG', 'K-GEHEIM', 'Interner Kontakt', 'planer@example.org', 'wire:'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $html, $secret);
        }
    }

    public function test_unbekannt_404_gesperrt_und_abgelaufen_410(): void
    {
        $this->get('/advancing/' . str_repeat('a', 48))->assertNotFound();
        $this->get('/advancing/zu-kurz')->assertNotFound();

        $this->advancing->update(['revoked_at' => now()]);
        $this->get("/advancing/{$this->token}")->assertStatus(410)->assertSee('gesperrt');
        $this->answer(['busse' => '2'])->assertStatus(410);

        $this->advancing->update(['revoked_at' => null]);
        $this->travelTo(Carbon::parse('2026-12-07 00:00'));
        $this->get("/advancing/{$this->token}")->assertStatus(410)->assertSee('abgelaufen');
        $this->assertNull($this->advancing->fresh()->answers);
    }

    public function test_modul_aus_404(): void
    {
        Setting::put(Setting::MODULES, 'saalplan');
        app()->forgetScopedInstances();

        $this->get("/advancing/{$this->token}")->assertNotFound();
        $this->answer(['busse' => '2'])->assertNotFound();
    }

    public function test_erster_aufruf_setzt_geoeffnet(): void
    {
        $this->assertNull($this->advancing->fresh()->first_opened_at);
        $this->get("/advancing/{$this->token}")->assertOk();
        $first = $this->advancing->fresh()->first_opened_at;
        $this->assertNotNull($first);
        $this->assertSame('geöffnet', $this->advancing->fresh()->statusLabel());

        $this->travel(2)->hours();
        $this->get("/advancing/{$this->token}")->assertOk();
        $this->assertTrue($first->equalTo($this->advancing->fresh()->first_opened_at));
    }

    public function test_speichern_merkt_nur_abweichungen(): void
    {
        $this->answer(['busse' => '2', 'get_in' => '11:30 Uhr', 'pkw' => ''])
            ->assertRedirect("/advancing/{$this->token}")
            ->assertSessionHas('status', 'Gespeichert.');

        $advancing = $this->advancing->fresh();
        $this->assertSame(['busse' => '2'], $advancing->answers);
        $this->assertNotNull($advancing->last_saved_at);
        $this->assertNull($advancing->submitted_at);

        // Der Planer ändert seinen Vorschlag: Unangefasstes folgt
        $advancing->update(['prefill' => [...$advancing->prefill, 'get_in' => '12:00 Uhr ok?']]);
        $this->get("/advancing/{$this->token}")->assertSee('value="12:00 Uhr ok?"', false);

        // Zurück auf die Vorbelegung: die Antwort fällt weg
        $this->answer(['busse' => '', 'einlass' => '18:00 Uhr'], shown: ['busse' => '2', 'einlass' => '']);
        // busse ist ohne Vorbelegung – leer heißt dort: keine eigene Antwort mehr
        $this->assertSame(['einlass' => '18:00 Uhr'], $this->advancing->fresh()->answers);
        $this->answer(['einlass' => ''], shown: ['einlass' => '18:00 Uhr']);
        $this->assertNull($this->advancing->fresh()->answers);
        // Eine vorbelegte Frage leeren ist dagegen eine Antwort
        $this->answer(['get_in' => ''], shown: ['get_in' => '12:00 Uhr ok?']);
        $this->assertSame(['get_in' => ''], $this->advancing->fresh()->answers);
    }

    public function test_gleichzeitiges_speichern_ueberschreibt_nichts(): void
    {
        // A und B haben die Seite mit leeren Feldern geladen
        $loaded = ['busse' => '', 'pkw' => ''];
        $this->answer(['busse' => '2', 'pkw' => ''], shown: $loaded);
        $this->answer(['busse' => '', 'pkw' => '4'], shown: $loaded);

        $this->assertSame(['busse' => '2', 'pkw' => '4'], $this->advancing->fresh()->answers);
    }

    public function test_gesperrte_und_unbekannte_felder_werden_ignoriert(): void
    {
        $this->answer([
            'veranstaltung' => 'Anderer Titel',
            'datum' => '2027-01-01',
            'ap_advancing' => 'contact:999',
            'gibt_es_nicht' => 'x',
            'busse' => '1',
        ], shown: ['veranstaltung' => 'Comedy Abend', 'datum' => '2026-12-05', 'ap_advancing' => '', 'gibt_es_nicht' => '', 'busse' => ''])->assertRedirect();

        $this->assertSame(['busse' => '1'], $this->advancing->fresh()->answers);
        // Arrays statt Text: wie leer
        $this->post('/advancing/' . $this->token, ['aktion' => 'speichern', 'a' => ['pkw' => ['x']], 'o' => ['pkw' => '']])->assertRedirect();
        $this->assertSame(['busse' => '1'], $this->advancing->fresh()->answers);
    }

    public function test_ungueltige_eingaben_werden_abgelehnt(): void
    {
        $this->answer(['busse' => 'zwei', 'pkw' => '3'])
            ->assertRedirect("/advancing/{$this->token}")
            ->assertSessionHasErrors(['a.busse'])
            ->assertSessionHasInput('a.pkw', '3');
        // Nichts gespeichert, auch das gültige Feld nicht
        $this->assertNull($this->advancing->fresh()->answers);

        $this->answer(['pkw' => '10000'])->assertSessionHasErrors(['a.pkw']);
        $this->answer(['catering' => str_repeat('x', 501)])->assertSessionHasErrors(['a.catering']);
        $this->answer(['anmerkungen' => str_repeat('x', 5000)])->assertSessionHasNoErrors();
        $this->answer(['anmerkungen' => str_repeat('y', 5001)], shown: ['anmerkungen' => str_repeat('x', 5000)])->assertSessionHasErrors(['a.anmerkungen']);
    }

    public function test_pflichtfelder_nur_beim_absenden(): void
    {
        $template = AdvancingTemplate::create(['name' => 'Pflicht', 'structure' => [['title' => 'A', 'items' => [
            ['key' => 'rider', 'type' => 'text', 'label' => 'Rider', 'required' => true],
            ['key' => 'titel', 'type' => 'text', 'label' => 'Titel', 'required' => true, 'locked' => true],
        ]]]]);
        $event = Event::create(['title' => 'Zweites', 'status' => 'bestätigt', 'starts_at' => '2026-12-10 20:00:00']);
        $advancing = Advancings::start($event, $template);
        $token = $this->send($advancing);

        $this->answer([], 'speichern', [], $token)->assertSessionHasNoErrors();
        // Gesperrte Pflichtfragen füllt der Planer – sie halten das Absenden nicht auf
        $this->answer([], 'absenden', [], $token)->assertSessionHasErrors(['a.rider']);
        $this->assertNull($advancing->fresh()->submitted_at);

        $this->answer(['rider' => 'liegt bei'], 'absenden', ['rider' => ''], $token)->assertSessionHasNoErrors();
        $this->assertNotNull($advancing->fresh()->submitted_at);
    }

    public function test_absenden_zaehlt_und_mailt_planer_und_venue(): void
    {
        $this->answer(['busse' => '2'], 'absenden')
            ->assertRedirect("/advancing/{$this->token}")
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Abgeschickt') && str_contains($status, '06.12.2026'));

        $advancing = $this->advancing->fresh();
        $this->assertSame(1, $advancing->submit_count);
        $this->assertSame('abgegeben', $advancing->statusLabel());
        Mail::assertSent(AdvancingSubmittedMail::class, fn (AdvancingSubmittedMail $mail): bool => $mail->hasTo('planer@example.org')
            && $mail->hasTo('veranstaltungen@example.org')
            && str_contains($mail->render(), 'Comedy Abend')
            && str_contains($mail->render(), 'bereich=advancing'));

        $this->answer(['pkw' => '3'], 'absenden');
        $this->assertSame(2, $this->advancing->fresh()->submit_count);
        Mail::assertSent(AdvancingSubmittedMail::class, 2);
    }

    public function test_abgabe_ohne_empfaenger_ohne_mail(): void
    {
        Setting::put(Setting::MAIL_REPLY_TO, null);
        $this->planer->update(['is_active' => false]);

        $this->answer(['busse' => '2'], 'absenden')->assertRedirect();

        $this->assertSame(1, $this->advancing->fresh()->submit_count);
        Mail::assertNotSent(AdvancingSubmittedMail::class);
    }

    public function test_abgeschlossen_nur_lesen_und_post_410(): void
    {
        $this->answer(['busse' => '2']);
        $this->advancing->update(['closed_at' => now(), 'closed_by_name' => 'Planer']);

        $this->get("/advancing/{$this->token}")
            ->assertOk()
            ->assertSee('abgeschlossen')
            ->assertDontSee('name="a[busse]"', false)
            ->assertDontSee('Absenden');
        $this->answer(['busse' => '5'])->assertStatus(410);
        $this->assertSame(['busse' => '2'], $this->advancing->fresh()->answers);
    }

    public function test_antworten_werden_maskiert(): void
    {
        $this->answer(['catering' => '<script>alert(1)</script> & Co']);

        $html = (string) $this->get("/advancing/{$this->token}")->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co', $html);

        $this->answer(['busse' => '1'], 'absenden');
        Mail::assertSent(AdvancingSubmittedMail::class, fn (AdvancingSubmittedMail $mail): bool => !str_contains($mail->render(), '<script>alert(1)</script>'));
    }

    public function test_audit_nennt_veranstalter_link(): void
    {
        $this->answer(['busse' => '2']);

        $log = AuditLog::query()->where('subject', 'event_advancings')->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('Veranstalter (Advancing-Link)', $log->user_name);
        $this->assertNull($log->user_id);
        $this->assertSame($this->event->id, $log->event_id);
        Mail::assertSent(DaysheetMail::class);
    }
}
