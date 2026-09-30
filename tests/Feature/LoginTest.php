<?php

namespace Tests\Feature;

use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_startseite_leitet_zur_anmeldung(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_anmeldeseite_ist_deutsch(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Anmelden')
            ->assertSee('E-Mail-Adresse');
    }

    public function test_admin_sieht_das_dashboard(): void
    {
        $this->actingAs($this->admin())->get('/')->assertOk();
    }
}
