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

    public function test_startseite_nach_der_anmeldung_ist_die_event_liste(): void
    {
        $this->actingAs($this->admin())->get('/')->assertRedirect('/events');
    }

    public function test_ohne_event_recht_der_erste_erlaubte_menuepunkt(): void
    {
        $this->actingAs($this->userWith($this->role('pforte', ['codes' => 'read'])))->get('/')->assertRedirect('/codes');
    }
}
