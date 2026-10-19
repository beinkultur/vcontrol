<?php

namespace Tests\Feature;

use App\Enums\ColorScheme;
use App\Filament\Pages\ManageSystem;
use App\Mail\DaysheetMail;
use App\Models\Setting;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Mime\Part\DataPart;
use Tests\TestCase;

/** Farbschema (App\Enums\ColorScheme), Logo und Favicon. */
class BrandingTest extends TestCase
{
    public function test_ohne_einstellung_gilt_vc_basic(): void
    {
        $this->assertSame(ColorScheme::VcBasic, ColorScheme::current());

        $this->actingAs($this->admin())
            ->get('/events')
            ->assertOk()
            // Farben und Look der Website www.venuecontrol.eu
            ->assertSee('--primary-600:' . Color::convertToOklch('#2563eb'), false)
            ->assertSee('--gray-950:' . Color::convertToOklch('#0f1d3a'), false)
            ->assertSee('--warning-600:' . Color::convertToOklch('#d97706'), false)
            ->assertSee('--vc-buchung:#2563eb;--vc-planung:#0d9488;--vc-durchfuehrung:#d97706;', false)
            ->assertSee(asset('css/scheme-vc-basic.css') . '?v=', false)
            ->assertDontSee(Color::convertToOklch('#5c0a5a'), false)
            ->assertDontSee('scheme-lila-gruen.css', false)
            // Eigene Skripte (Doppelklick-Schutz der Menügruppen), einmal je Sitzung im SPA-Modus
            ->assertSee('<script src="' . asset('js/vcontrol.js') . '?v=', false)
            ->assertSee('defer data-navigate-once></script>', false);
        $this->assertFileExists(public_path('js/vcontrol.js'));
    }

    public function test_lila_gruen_mit_den_farben_des_t1x_backends(): void
    {
        // Skalen wie in Filament 3 (T1X): 500 ist die T1X-Farbe, 600 dunkler
        $primary = ColorScheme::LilaGruen->colors()['primary'];
        $this->assertSame(['#f7f3f7', '#660b64', '#5c0a5a', '#1f031e'], [$primary[50], $primary[500], $primary[600], $primary[950]]);
        $this->assertSame('#f6c876', ColorScheme::LilaGruen->colors()['warning'][500]);

        Setting::put(Setting::COLOR_SCHEME, ColorScheme::LilaGruen->value);

        // Auch ohne Anmeldung (Anmeldeseite)
        $this->get('/login')->assertOk()->assertSee('--vc-accent:#660B64;', false);

        $this->actingAs($this->admin())
            ->get('/events')
            ->assertOk()
            ->assertSee('--primary-600:' . Color::convertToOklch('#5c0a5a'), false)
            ->assertSee('--success-500:' . Color::convertToOklch('#46C7AF'), false)
            ->assertSee('--gray-950:' . Color::convertToOklch('#1A2421'), false)
            ->assertSee('--vc-buchung:var(--primary-600);--vc-planung:var(--success-500);', false)
            ->assertSee('--vc-planung-ink:var(--gray-950);', false)
            // Dunkle Kopfleiste wie im T1X-Veranstalterbereich
            ->assertSee(asset('css/scheme-lila-gruen.css') . '?v=', false)
            ->assertDontSee('scheme-vc-basic.css', false)
            ->assertDontSee('--primary-600:' . Color::convertToOklch('#2563eb'), false);
    }

    public function test_nur_der_system_admin_waehlt_das_farbschema(): void
    {
        $this->actingAs($this->admin())->get('/system')->assertForbidden();

        $this->actingAs($this->systemAdmin());
        Livewire::test(ManageSystem::class)
            ->assertSet('data.color_scheme', ColorScheme::VcBasic->value)
            ->assertSee('VC Basic')
            ->assertSee('Lila-Grün')
            ->fillForm(['color_scheme' => ColorScheme::LilaGruen->value])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(ManageSystem::getUrl());

        $this->assertSame(ColorScheme::LilaGruen, ColorScheme::current());

        // Unverändert gespeichert: kein Neuladen nötig
        Livewire::test(ManageSystem::class)
            ->call('save')
            ->assertNoRedirect();

        // Unbekannte Werte lehnt die Auswahl ab
        Livewire::test(ManageSystem::class)
            ->fillForm(['color_scheme' => 'neon'])
            ->call('save')
            ->assertHasFormErrors(['color_scheme']);
        $this->assertSame(ColorScheme::LilaGruen, ColorScheme::current());
    }

    public function test_logo_im_kopf_und_favicons(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('class="vc-brand__logo" src="' . asset('images/logo.svg') . '"', false)
            ->assertSee('<link rel="icon" href="' . asset('favicon.ico') . '" sizes="32x32">', false)
            ->assertSee('href="' . asset('images/favicon.svg') . '" type="image/svg+xml"', false)
            ->assertSee('<link rel="apple-touch-icon" href="' . asset('apple-touch-icon.png') . '">', false);

        // favicon.ico mit 16, 32 und 48 px
        $ico = file_get_contents(public_path('favicon.ico'));
        $this->assertSame("\0\0\1\0\3\0", substr($ico, 0, 6));
        $this->assertSame([16, 32, 48], array_map(fn (int $i): int => ord($ico[6 + 16 * $i]), [0, 1, 2]));

        foreach (['images/logo.svg', 'images/favicon.svg'] as $svg) {
            $this->assertStringContainsString('viewBox="0 0 591 591"', file_get_contents(public_path($svg)), $svg);
        }
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('apple-touch-icon.png')), 0, 2));
        $this->assertSame([120, 120], array_slice(getimagesize(public_path('images/logo-mail.png')), 0, 2));
    }

    public function test_logo_unter_der_zeile_im_fuss_der_daysheet_mail(): void
    {
        $link = 'https://example.org/daysheet/abc';
        Mail::to('buero@halle.de')->send(new DaysheetMail('Daysheet', "Hier:\n\n{$link}", link: $link));

        $email = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $logo = collect($email->getAttachments())->first(fn (DataPart $part): bool => $part->getDisposition() === 'inline');

        $this->assertNotNull($logo, 'Logo hängt eingebettet an der Mail');
        $this->assertSame('image/png', $logo->getMediaType() . '/' . $logo->getMediaSubtype());
        $this->assertMatchesRegularExpression('#erstellt mit <a href="https://www\.venuecontrol\.eu"[^>]*>VenueControl</a>.*?<img src="cid:[^"]+" width="40" height="40"#s', $email->getHtmlBody());
        $this->assertStringNotContainsString('<img', $email->getTextBody());
    }
}
