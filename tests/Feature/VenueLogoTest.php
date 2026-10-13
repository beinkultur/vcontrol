<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageVenue;
use App\Mail\CostingMail;
use App\Mail\DaysheetMail;
use App\Models\Costing;
use App\Models\Event;
use App\Models\Setting;
use App\Models\User;
use App\Support\CostingPdf;
use App\Support\VenueLogo;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Tests\TestCase;

/** Logo der Halle (Verwaltung › Halle): hochladen, ersetzen, auf dem Costing, im Daysheet und in den Mails. */
class VenueLogoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Storage::fake(VenueLogo::DISK);
    }

    public function test_admin_laedt_logo_hoch_es_steht_auf_dem_costing(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(ManageVenue::class)
            ->fillForm(['venue_name' => 'Inselpark Arena', 'venue_logo' => UploadedFile::fake()->image('ipa.png', 600, 170)])
            ->call('save')
            ->assertHasNoFormErrors();

        $path = (string) Setting::lookup(Setting::VENUE_LOGO);
        Storage::disk(VenueLogo::DISK)->assertExists($path);
        $this->get(route('venue.logo'))->assertOk()->assertHeader('Content-Type', 'image/png');

        // Eingepasst in 40 × 12 mm (Kopfzeile des Costings), Seitenverhältnis bleibt
        $logo = VenueLogo::fitted(40, 12);
        $this->assertSame(40.0, $logo['width']);
        $this->assertSame(11.33, $logo['height']);
        $this->assertStringStartsWith('data:image/png;base64,', $logo['src']);

        // Auf dem Costing: mittig in der Kopfzeile jeder Seite statt des Hallennamens
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
        $costing = Costing::createFor($event, null);
        $html = view('costing-pdf', ['costing' => $costing, 'event' => $event, 'venue' => 'Inselpark Arena', 'venueLogo' => $logo, 'sections' => []])->render();
        $this->assertStringContainsString('<div class="page-head"><img src="data:image/png;base64,', $html);
        $this->assertStringNotContainsString('<div class="venue">', $html);
        $this->assertStringStartsWith('%PDF', CostingPdf::render($costing));

        // Ersetzen (in FilePond: das alte entfernen, das neue wählen): das alte verschwindet von der Platte
        Livewire::test(ManageVenue::class)
            ->set('data.venue_logo', [])
            ->fillForm(['venue_logo' => UploadedFile::fake()->image('neu.jpg', 400, 200)])
            ->call('save')
            ->assertHasNoFormErrors();
        Storage::disk(VenueLogo::DISK)->assertMissing($path);
        $this->get(route('venue.logo'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_nur_bilder_und_nur_fuer_angemeldete(): void
    {
        $this->get(route('venue.logo'))->assertRedirect();

        $this->actingAs($this->admin());
        $this->get(route('venue.logo'))->assertNotFound();
        $this->assertNull(VenueLogo::fitted(62, 20));

        // Nur PNG und JPG
        Livewire::test(ManageVenue::class)
            ->fillForm(['venue_name' => 'Inselpark Arena', 'venue_logo' => UploadedFile::fake()->image('logo.gif', 300, 100)])
            ->call('save')
            ->assertHasFormErrors(['venue_logo']);
        $this->assertNull(Setting::lookup(Setting::VENUE_LOGO));

        // Kein Bild hinter dem gespeicherten Pfad (am Inhalt erkannt): kein Logo, nirgends
        Storage::disk(VenueLogo::DISK)->put('venue/logo.png', '%PDF-1.4 kein Bild');
        Setting::put(Setting::VENUE_LOGO, 'venue/logo.png');
        $this->assertNull(VenueLogo::path());
        $this->assertNull(VenueLogo::fitted(62, 20));
        $this->get(route('venue.logo'))->assertNotFound();
    }

    public function test_im_kopf_von_daysheet_und_mails(): void
    {
        Setting::put(Setting::VENUE_NAME, 'Inselpark Arena');
        Storage::disk(VenueLogo::DISK)->putFileAs('venue', UploadedFile::fake()->image('ipa.png', 600, 170), 'ipa.png');
        Setting::put(Setting::VENUE_LOGO, 'venue/ipa.png');

        // Daysheet: rechts neben dem Titel, als data-URI (die Seite braucht keine Anmeldung)
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
        $this->actingAs($this->admin())
            ->get(route('events.daysheet-preview', $event))
            ->assertOk()
            ->assertSee('<img class="vc-sheet__logo" src="data:image/png;base64,', false)
            ->assertSee('width="200" height="57" alt="Inselpark Arena">', false);

        // Mails: im Kopf eingebettet statt des Namens, das VenueControl-Logo bleibt im Fuß
        $link = 'https://example.org/daysheet/abc';
        Mail::to('buero@halle.de')->send(new DaysheetMail('Daysheet', "Hier:\n\n{$link}", link: $link));
        Mail::to('buero@halle.de')->send(new CostingMail('Costing', 'Anbei das Costing.', '%PDF-1.4', 'costing.pdf'));
        $notification = new ResetPasswordNotification('abc');
        $notification->url = 'https://example.org/passwort/neu';
        User::factory()->create()->notify($notification);

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(3, $sent);
        foreach ($sent as $message) {
            /** @var Email $email */
            $email = $message->getOriginalMessage();
            $inline = collect($email->getAttachments())->filter(fn (DataPart $part): bool => $part->getDisposition() === 'inline');
            $this->assertCount(2, $inline, 'Logo der Halle und VenueControl-Logo, eingebettet');
            $this->assertMatchesRegularExpression('#class="header".*?<img src="cid:[^"]+" width="200" height="57" alt="Inselpark Arena"#s', $email->getHtmlBody());
            $this->assertMatchesRegularExpression('#<img src="cid:[^"]+" width="40" height="40" alt="VenueControl"#', $email->getHtmlBody());
            $this->assertStringNotContainsString('<img', (string) $email->getTextBody());
        }
    }
}
