<?php

namespace Tests\Feature;

use App\Enums\ServiceCode;
use App\Filament\Pages\ManageVenue;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\DaysheetsRelationManager;
use App\Mail\DaysheetMail;
use App\Models\AuditLog;
use App\Models\Daysheet;
use App\Models\Event;
use App\Models\EventFile;
use App\Models\EventFileTag;
use App\Models\Setting;
use App\Models\Trade;
use App\Support\Daysheets;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DaysheetTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Mail::fake();
        Storage::fake(EventFile::DISK);
        // Feste Zeit vor dem Event (Fr 09.10.2026), sonst läuft der Test nach dem 10.10. ab
        $this->travelTo(Carbon::parse('2026-10-06 10:00'));
        Setting::put(Setting::VENUE_NAME, 'Inselpark Arena');

        $this->event = Event::create(['title' => 'Konzert Nord', 'status' => 'bestätigt', 'starts_at' => '2026-10-09 20:00:00']);
        $licht = Trade::create(['name' => 'Licht & Ton', 'email' => ' Licht@Example.org ']);
        $buehne = Trade::create(['name' => 'Bühnenbau ohne Mail']);
        $this->event->services()->create(['service' => ServiceCode::Vt, 'trade_id' => $licht->id]);
        $this->event->services()->create(['service' => ServiceCode::StageSetup, 'trade_id' => $buehne->id]);
        $this->event->schedule()->create(['admission' => '18:30:00']);
    }

    /** @return array{Daysheet, string} Daysheet und Schlüssel aus der verschickten Mail */
    private function send(): array
    {
        $daysheet = Daysheets::send($this->event, ['buero@halle.de'], ['licht@example.org'], 'Daysheet {event}', "Hallo,\n{link}");
        $mail = Mail::sent(DaysheetMail::class)->last();
        preg_match('#/daysheet/([A-Za-z0-9]{48})#', $mail->text, $match);

        return [$daysheet, $match[1]];
    }

    public function test_vorbelegung_mit_standards_der_halle_und_gewerken(): void
    {
        $defaults = Daysheets::defaults($this->event);
        $this->assertSame([], $defaults['to']);
        $this->assertSame(['licht@example.org'], $defaults['bcc']);
        $this->assertSame('Daysheet Konzert Nord – Fr, 09.10.2026', $defaults['subject']);
        $this->assertStringContainsString('{link}', $defaults['body']);
        $this->assertStringContainsString('Inselpark Arena', $defaults['body']);

        Setting::put(Setting::DAYSHEET_TO, 'buero@halle.de');
        Setting::put(Setting::DAYSHEET_SUBJECT, 'DS {event}');
        $this->assertSame(['buero@halle.de'], Daysheets::defaults($this->event)['to']);
        $this->assertSame('DS Konzert Nord', Daysheets::defaults($this->event)['subject']);
    }

    public function test_eventmanager_verschickt_mit_link_bis_zum_tag_nach_der_veranstaltung(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 10:00'));
        $planer = $this->userWith($this->role('eventmanager', ['events' => 'edit']));
        $this->actingAs($planer);

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->callAction('sendDaysheet', data: [
                'to' => ['buero@halle.de'],
                'bcc' => ['licht@example.org', 'Extra@Example.org'],
                'subject' => 'Daysheet {event}',
                'body' => "Hallo,\nhier: {link}\nGültig bis {gueltig_bis}.",
            ])
            ->assertHasNoActionErrors();

        $daysheet = $this->event->daysheets()->firstOrFail();
        $this->assertSame(['buero@halle.de'], $daysheet->recipients_to);
        $this->assertSame(['licht@example.org', 'extra@example.org'], $daysheet->recipients_bcc);
        $this->assertSame('2026-10-10 23:59', $daysheet->expires_at->format('Y-m-d H:i'));
        $this->assertSame('Daysheet Konzert Nord', $daysheet->subject);
        // Der Schlüssel steht weder in der Datenbank noch im Audit
        $this->assertStringContainsString('{link}', $daysheet->body);
        $log = AuditLog::query()->where('subject', 'daysheets')->firstOrFail();
        $this->assertSame('***', $log->new_values['token_hash']);

        Mail::assertSent(DaysheetMail::class, fn (DaysheetMail $mail): bool => $mail->hasTo('buero@halle.de')
            && $mail->hasBcc('licht@example.org')
            && $mail->hasBcc('extra@example.org')
            && $mail->subjectLine === 'Daysheet Konzert Nord'
            && preg_match('#https?://[^\s]+/daysheet/[A-Za-z0-9]{48}#', $mail->text) === 1
            && str_contains($mail->text, 'Gültig bis 10.10.2026, 23:59 Uhr.')
            && $mail->replyToAddress === $planer->email);
    }

    public function test_gueltig_bis_zum_ende_des_tages_nach_der_veranstaltung(): void
    {
        $until = fn (string $start, ?string $end): ?string => Daysheets::validUntil(
            new Event(['title' => 'X', 'starts_at' => $start, 'ends_at' => $end]),
        )?->format('d.m.Y H:i:s');

        $this->assertSame('10.10.2026 23:59:59', $until('2026-10-09 20:00', null));
        // Ganztags-Import: Ende exklusiv um 00:00 des Folgetags
        $this->assertSame('10.10.2026 23:59:59', $until('2026-10-09 00:00', '2026-10-10 00:00'));
        // Show bis nach Mitternacht zählt zum Vortag
        $this->assertSame('10.10.2026 23:59:59', $until('2026-10-09 20:00', '2026-10-10 01:30'));
        // Mehrtägig: Messe Di bis Do, exklusiv gespeichert
        $this->assertSame('04.12.2026 23:59:59', $until('2026-12-01 00:00', '2026-12-04 00:00'));
        // Mehrtägig mit echter Endzeit am letzten Tag
        $this->assertSame('12.10.2026 23:59:59', $until('2026-10-09 10:00', '2026-10-11 18:00'));
        $this->assertNull(Daysheets::validUntil(new Event(['title' => 'Ohne Datum'])));
    }

    public function test_nach_der_veranstaltung_kein_neuer_link(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 09:00'));
        $this->actingAs($this->userWith($this->role('eventmanager', ['events' => 'edit'])));

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->callAction('sendDaysheet', data: ['to' => ['buero@halle.de'], 'subject' => 'X', 'body' => 'Y']);

        Mail::assertNothingSent();
        $this->assertSame(0, Daysheet::query()->count());
    }

    public function test_ohne_empfaenger_unter_an_kein_versand(): void
    {
        $this->actingAs($this->userWith($this->role('eventmanager', ['events' => 'edit'])));

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->callAction('sendDaysheet', data: ['to' => [], 'bcc' => ['licht@example.org'], 'subject' => 'X', 'body' => 'Y'])
            ->assertHasActionErrors(['to']);
        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->callAction('sendDaysheet', data: ['to' => ['keine-adresse'], 'subject' => 'X', 'body' => 'Y'])
            ->assertHasActionErrors();

        Mail::assertNothingSent();
        $this->assertSame(0, Daysheet::query()->count());
    }

    public function test_link_ohne_konto_bis_ablauf_oder_sperre(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 10:00'));
        [$daysheet, $token] = $this->send();

        $this->get('/daysheet/' . $token)
            ->assertOk()
            ->assertSee('Konzert Nord')
            ->assertSee('18:30 Uhr')
            ->assertSee('Drucken / als PDF speichern')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get('/daysheet/' . str_repeat('a', 48))->assertNotFound();
        $this->get('/daysheet/zu-kurz')->assertNotFound();

        // Gültig bis zum Ende des Tages nach der Veranstaltung (Fr 09.10. → Sa 10.10., 23:59)
        $this->travelTo(Carbon::parse('2026-10-10 23:59'));
        $this->get('/daysheet/' . $token)->assertOk();
        $this->travelTo(Carbon::parse('2026-10-11 00:00:01'));
        $this->get('/daysheet/' . $token)->assertStatus(410)->assertSee('abgelaufen');

        $this->travelTo(Carbon::parse('2026-10-07 10:00'));
        $daysheet->update(['revoked_at' => now()]);
        $this->get('/daysheet/' . $token)->assertStatus(410)->assertSee('gesperrt');
    }

    public function test_dateien_ueber_den_link_ohne_verborgene(): void
    {
        $tag = EventFileTag::create(['name' => 'Technical Rider', 'sort_order' => 1]);
        $file = function (Event $event, string $name, bool $hidden = false) use ($tag): EventFile {
            Storage::disk(EventFile::DISK)->put('event-files/' . $name, '%PDF-1.4');

            return $event->files()->create(['tag_id' => $tag->id, 'path' => 'event-files/' . $name, 'original_name' => $name,
                'mime_type' => 'application/pdf', 'size' => 8, 'version' => 1, 'hidden_from_externals' => $hidden]);
        };
        $rider = $file($this->event, 'Rider.pdf');
        $vertrag = $file($this->event, 'Vertrag.pdf', hidden: true);
        $fremd = $file(Event::create(['title' => 'Andere', 'starts_at' => now()]), 'Fremd.pdf');
        [, $token] = $this->send();

        $this->get('/daysheet/' . $token)->assertSee('Rider.pdf')->assertDontSee('Vertrag.pdf');
        $this->get(route('daysheet.file', [$token, $rider]))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('daysheet.file', [$token, $vertrag]))->assertNotFound();
        $this->get(route('daysheet.file', [$token, $fremd]))->assertNotFound();
    }

    public function test_sperren_nur_mit_schreibrecht(): void
    {
        [$daysheet] = $this->send();

        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        Livewire::test(DaysheetsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => ViewEvent::class])
            ->assertCanSeeTableRecords([$daysheet])
            ->assertTableActionHidden('revoke', $daysheet)
            ->assertTableActionHidden('sendDaysheet');
        $this->get('/events/' . $this->event->id . '/daysheet')->assertOk()->assertSee('Vorschau');

        $this->actingAs($this->userWith($this->role('eventmanager', ['events' => 'edit'])));
        Livewire::test(DaysheetsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class])
            ->callTableAction('revoke', $daysheet);
        $this->assertTrue($daysheet->fresh()->isRevoked());
    }

    public function test_standards_in_der_verwaltung(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageVenue::class)
            ->fillForm([
                'daysheet_to' => ['Buero@Halle.de'],
                'daysheet_subject' => 'DS {event}',
                'daysheet_text' => "Hallo,\r\n{link}",
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('buero@halle.de', Setting::lookup(Setting::DAYSHEET_TO));
        $this->assertSame('DS {event}', Setting::lookup(Setting::DAYSHEET_SUBJECT));
        $this->assertSame("Hallo,\n{link}", Setting::lookup(Setting::DAYSHEET_TEXT));
    }
}
