<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageVenue;
use App\Models\Costing;
use App\Models\Event;
use App\Models\Setting;
use App\Support\CostingPdf;
use App\Support\VenueLogo;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Logo der Halle (Verwaltung › Halle): hochladen, ersetzen, auf dem Costing. */
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

        // Eingepasst in 62 × 20 mm, Seitenverhältnis bleibt
        $logo = VenueLogo::forPdf(62, 20);
        $this->assertSame(62.0, $logo['width']);
        $this->assertSame(17.57, $logo['height']);
        $this->assertStringStartsWith('data:image/png;base64,', $logo['src']);

        // Auf dem Costing: das Logo statt des Hallennamens
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
        $costing = Costing::createFor($event, null);
        $html = view('costing-pdf', ['costing' => $costing, 'event' => $event, 'venue' => 'Inselpark Arena', 'venueLogo' => $logo, 'sections' => [], 'logo' => ''])->render();
        $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
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
        $this->assertNull(VenueLogo::forPdf(62, 20));

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
        $this->assertNull(VenueLogo::forPdf(62, 20));
        $this->get(route('venue.logo'))->assertNotFound();
    }
}
