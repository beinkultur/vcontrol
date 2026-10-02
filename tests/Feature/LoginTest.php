<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_richtiges_passwort_ohne_zugang_bekommt_eigene_meldung(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));
        User::factory()->create(['email' => 'ohne-rechte@example.org', 'password' => 'richtig-123']);

        // Falsches Passwort: wie bisher die allgemeine Meldung – verrät nichts
        $falsch = Livewire::test(Login::class)
            ->fillForm(['email' => 'ohne-rechte@example.org', 'password' => 'falsch-123'])
            ->call('authenticate');
        $this->assertSame(__('filament-panels::auth/pages/login.messages.failed'), $falsch->errors()->first('data.email'));

        // Richtiges Passwort, aber kein Bereich: eigene Meldung, nicht angemeldet
        $richtig = Livewire::test(Login::class)
            ->fillForm(['email' => 'ohne-rechte@example.org', 'password' => 'richtig-123'])
            ->call('authenticate');
        $this->assertSame(Login::NO_ACCESS, $richtig->errors()->first('data.email'));
        $this->assertGuest();
    }

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
