<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Wartungsmodus (php artisan down): eigene 503-Seite mit Hinweis statt Laravels englischer. */
class MaintenancePageTest extends TestCase
{
    public function test_wartungsseite_mit_hinweis(): void
    {
        Setting::put(Setting::VENUE_NAME, 'Inselpark Arena');
        // Treiber „array“ (phpunit.xml): betrifft nur diesen Test, nie die Live-Seite
        $this->app->maintenanceMode()->activate(['retry' => 60]);

        $this->get('/login')
            ->assertStatus(503)
            ->assertSee('Wartungsarbeiten')
            ->assertSee('in wenigen Minuten wieder da')
            ->assertSee('Inselpark Arena')
            ->assertSee('http-equiv="refresh"', false);

        $this->app->maintenanceMode()->deactivate();
        $this->get('/login')->assertOk();
    }

    public function test_laesst_sich_vorab_rendern_auch_ohne_einstellungen(): void
    {
        // So rendert `php artisan down --render="errors::503"` die Seite beim Einspielen
        $this->assertStringContainsString('Wartungsarbeiten', view('errors.503')->render());

        // Antwortet die Datenbank nicht, kommt die Seite trotzdem – ohne Hallenname, in der Grundfarbe
        Schema::drop('settings');
        $html = view('errors.503')->render();
        $this->assertStringContainsString('Wartungsarbeiten', $html);
        $this->assertStringContainsString('--accent: #2563eb', $html);
    }
}
