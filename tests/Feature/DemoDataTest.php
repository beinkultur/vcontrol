<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\EventResource;
use App\Models\AuditLog;
use App\Models\CalendarEntry;
use App\Models\Costing;
use App\Models\Daysheet;
use App\Models\Event;
use App\Models\Inquiry;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Promoter;
use App\Models\SeatingPlan;
use App\Models\Setting;
use App\Models\User;
use App\Support\Inquiries;
use App\Support\MeterStats;
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
        // Alle Stände: vergangen und abgeschlossen, bestätigt, fraglich, storniert
        $this->assertTrue(Event::query()->where('closed', true)->whereDate('starts_at', '<', today())->exists());
        foreach (['bestätigt', 'fraglich', 'storniert'] as $status) {
            $this->assertTrue(Event::query()->where('status', $status)->whereDate('starts_at', '>', today())->exists(), $status);
        }

        // Optionen sind Anfragen: am selben Tag 1. und 2. Option, ein Tag durch ein Event belegt
        $rave = Inquiry::query()->where('title', 'Electro Winter Rave')->sole();
        $fair = Inquiry::query()->where('title', 'Hochzeitsmesse Hafenstadt')->sole();
        $this->assertSame(1, Inquiries::slots($rave)[$rave->dates->first()->id]['rank']);
        $this->assertSame(2, Inquiries::slots($fair)[$fair->dates->first()->id]['rank']);
        $party = Inquiry::query()->where('title', 'Schlagerparty XXL')->sole();
        $this->assertFalse(Inquiries::slots($party)[$party->dates->first()->id]['available'], 'fällt auf das Schlager-Herbstfest');
        $this->assertTrue(Inquiry::query()->where('status', Inquiry::CONFIRMED)->whereNotNull('event_id')->exists());
        $this->assertTrue(Inquiry::query()->where('status', Inquiry::WITHDRAWN)->exists());
        $this->assertTrue(CalendarEntry::query()->where('calendar_key', 'wartung')->exists());
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

        // Zähler: Strom je Event mit Monatsständen dazwischen, Wasser mit Zählerwechsel, Fernwärme –
        // nirgends ein Rücksprung, also passen die Monatsstände zu den Ständen der Events
        $this->assertSame(['Strom', 'Wasser', 'Fernwärme'], Meter::query()->ordered()->pluck('name')->all());
        $power = Meter::query()->where('name', 'Strom')->sole();
        $past = Event::query()->whereHas('operation')->count();
        $this->assertSame(2 * $past, $power->readings()->whereNotNull('event_id')->count());
        $this->assertGreaterThanOrEqual(24, $power->readings()->whereNull('event_id')->count());
        $this->assertSame(1, MeterReading::query()->where('restart', true)->count());
        foreach (Meter::all() as $meter) {
            $stats = MeterStats::for($meter);
            foreach ($stats->readings as $reading) {
                $this->assertGreaterThanOrEqual(0, $stats->delta($reading) ?? 0, $meter->name . ' am ' . $reading->read_at);
            }
            $this->assertNotNull($stats->year((int) today()->format('Y'))['previous'], $meter->name . ': Vorjahr zum Vergleich');
        }

        // Erfunden: Mailadressen nur auf .example, ein Eintrag im Änderungsprotokoll
        $this->assertSame(0, User::query()->where('email', 'not like', '%.example')->count());
        $this->assertSame(0, Promoter::query()->where('email', 'not like', '%.example')->count());
        $this->assertSame(1, AuditLog::query()->count());

        // Die Seiten laufen mit den Daten
        $this->actingAs($this->admin());
        $this->get('/events')->assertOk()->assertSee('Hafenstadt Lions');
        $this->get('/anfragen')->assertOk()->assertSee('Electro Winter Rave');
        $this->get('/zaehler')->assertOk()->assertSee('Fernwärme')->assertSee('Verbrauch je Event in kWh');
        $swap = MeterReading::query()->where('restart', true)->sole();
        $this->get('/zaehler?zaehler=' . $swap->meter_id . '&jahr=' . $swap->read_at->year)->assertOk()->assertSee('Zählerwechsel');
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
