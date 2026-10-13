<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\EventResource;
use App\Models\AuditLog;
use App\Models\Costing;
use App\Models\Daysheet;
use App\Models\Event;
use App\Models\Promoter;
use App\Models\SeatingPlan;
use App\Models\Setting;
use App\Models\User;
use App\Support\StagePodests;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Demodaten (vc:demo-data) für demo.vcontrol.eu: nur in einer leeren Installation, alles erfunden. */
class DemoDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Storage::fake(SeatingPlan::DISK);
    }

    public function test_fuellt_eine_leere_installation(): void
    {
        $this->artisan('vc:demo-data', ['--no-interaction' => true])->assertSuccessful();

        $this->assertSame('Be-In Arena', Setting::lookup(Setting::VENUE_NAME));
        $this->assertSame(DemoSeeder::eventCount(), Event::query()->count());
        // Alle Stände: vergangen und abgeschlossen, bestätigt, Option, fraglich, storniert
        $this->assertTrue(Event::query()->where('closed', true)->whereDate('starts_at', '<', today())->exists());
        foreach (['bestätigt', 'Option', 'fraglich', 'storniert'] as $status) {
            $this->assertTrue(Event::query()->where('status', $status)->whereDate('starts_at', '>', today())->exists(), $status);
        }
        $this->assertSame(3, SeatingPlan::query()->count());
        foreach (SeatingPlan::all() as $plan) {
            Storage::disk(SeatingPlan::DISK)->assertExists($plan->path);
            $this->assertSame('application/pdf', $plan->mime_type);
        }
        $this->assertTrue(Costing::query()->whereNotNull('sent_at')->exists());
        $this->assertTrue(Daysheet::query()->where('expires_at', '>', now())->exists(), 'ein gültiges Daysheet für die nächsten Tage');

        // Podest-Summe wie beim Speichern im Formular
        $stage = Event::query()->where('title', 'Rock im Herbst: The Velvet Engines')->sole()->stage;
        $this->assertSame(StagePodests::calculate($stage->only(['width', 'depth', 'wing_sl_width', 'wing_sl_depth', 'wing_sr_width', 'wing_sr_depth', 'rollpodest_width', 'rollpodest_depth', 'extra_platforms']))['total'], (int) $stage->podest_total);

        // Erfunden: Mailadressen nur auf .example, ein Eintrag im Änderungsprotokoll
        $this->assertSame(0, User::query()->where('email', 'not like', '%.example')->count());
        $this->assertSame(0, Promoter::query()->where('email', 'not like', '%.example')->count());
        $this->assertSame(1, AuditLog::query()->count());

        // Die Seiten laufen mit den Daten
        $this->actingAs($this->admin());
        $this->get('/events')->assertOk()->assertSee('Hafenstadt Lions')->assertSee('Option');
        $this->get(EventResource::getUrl('edit', ['record' => Event::query()->where('closed', true)->first()]))->assertOk();
        $this->get(EventResource::getUrl('edit', ['record' => Event::query()->where('title', 'Rock im Herbst: The Velvet Engines')->sole()]))->assertOk();
    }

    public function test_nie_ueber_vorhandene_daten(): void
    {
        Event::create(['title' => 'Echtes Event', 'starts_at' => now()->addWeek()]);

        $this->artisan('vc:demo-data', ['--no-interaction' => true])->assertFailed();
        $this->assertSame(1, Event::query()->count());
        $this->assertNull(Setting::lookup(Setting::VENUE_NAME));
    }
}
