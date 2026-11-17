<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\VenueIdentity;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Name der Venue an einer Stelle (Vorstufe „Mehrere Venues“, 17.11.2026) */
class VenueIdentityTest extends TestCase
{
    public function test_name_mit_rueckfall(): void
    {
        config(['app.name' => 'VenueControl']);
        $this->assertNull(VenueIdentity::configured());
        $this->assertSame('VenueControl', VenueIdentity::name());

        Setting::put(Setting::VENUE_NAME, ' LKH Arena ');
        $this->assertSame('LKH Arena', VenueIdentity::configured());
        $this->assertSame('LKH Arena', VenueIdentity::name());

        Setting::put(Setting::VENUE_NAME, '');
        $this->assertNull(VenueIdentity::configured());
        $this->assertSame('VenueControl', VenueIdentity::name());
    }

    public function test_ohne_tabelle_kein_fehler(): void
    {
        // Wartungsseite beim Einspielen: Code neu, Datenbank (noch) nicht
        config(['app.name' => 'VenueControl']);
        Schema::drop('settings');

        $this->assertNull(VenueIdentity::configured());
        $this->assertSame('VenueControl', VenueIdentity::name());
    }
}
