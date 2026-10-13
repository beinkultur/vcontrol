<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\AuditLog;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_jeder_aendert_sein_passwort_mit_dem_aktuellen(): void
    {
        $user = $this->userWith($this->role('catering', ['events' => 'read']));
        $user->update(['password' => 'altes-Passwort-1']);
        $this->actingAs($user = $user->fresh());

        $this->get('/profile')->assertOk()->assertSee('Passwort ändern')->assertSee($user->email);

        // Ohne das richtige aktuelle Passwort bleibt alles beim Alten
        Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'neues-Passwort-2', 'passwordConfirmation' => 'neues-Passwort-2', 'currentPassword' => 'falsch'])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);
        $this->assertTrue(Hash::check('altes-Passwort-1', $user->fresh()->password));

        Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'neues-Passwort-2', 'passwordConfirmation' => 'neues-Passwort-2', 'currentPassword' => 'altes-Passwort-1'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('neues-Passwort-2', $user->fresh()->password));

        // Im Audit nur maskiert
        $log = AuditLog::query()->where('subject', 'users')->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('***', $log->new_values['password']);
    }

    public function test_kalender_standard_ansicht_ohne_passwort_speichern(): void
    {
        $user = $this->userWith($this->role('team', ['kalender' => 'read']));
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->assertSchemaStateSet(['calendar_view' => 'month'])
            ->fillForm(['calendar_view' => 'week'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('week', $user->fresh()->calendar_view);

        Livewire::test(EditProfile::class)
            ->fillForm(['calendar_view' => 'jahr'])
            ->call('save')
            ->assertHasFormErrors(['calendar_view']);
    }

    public function test_adresse_des_persoenlichen_kalenders(): void
    {
        $user = $this->userWith($this->role('team', ['kalender' => 'read']));
        $this->actingAs($user);

        $this->get('/profile')->assertOk()->assertSee('Persönlicher Kalender zum Abonnieren');
        $user->refresh();
        $token = $user->personalFeedToken();
        $this->assertSame(40, strlen($token));
        $this->get('/profile')->assertSee('/kalender/persoenlich.ics?token=' . $token, false);
        // Verschlüsselt gespeichert, gesucht wird über den Hash; das Audit sieht nichts davon
        $this->assertStringNotContainsString($token, (string) $user->getRawOriginal('calendar_token'));
        $this->assertSame(hash('sha256', $token), $user->calendar_token_hash);
        $this->assertFalse(AuditLog::query()->where('subject', 'users')->where('action', 'updated')->exists(), 'das erste Anlegen ohne Eintrag');
        $this->get('/kalender/persoenlich.ics?token=' . $token)->assertOk();

        // Neue Adresse: die alte gilt nicht mehr
        Livewire::test(EditProfile::class)
            ->callAction(TestAction::make('renewPersonalFeed')->schemaComponent('personalFeed', 'form'));
        $renewed = $user->fresh()->personalFeedToken();
        $this->assertNotSame($token, $renewed);
        $this->get('/kalender/persoenlich.ics?token=' . $token)->assertForbidden();
        $this->get('/kalender/persoenlich.ics?token=' . $renewed)->assertOk();
        $log = AuditLog::query()->where('subject', 'users')->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('***', $log->new_values['calendar_token']);
        $this->assertSame('***', $log->new_values['calendar_token_hash']);
    }

    public function test_ohne_kalender_kein_kalender_im_profil(): void
    {
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));

        $this->get('/profile')->assertOk()->assertDontSee('Standard-Ansicht')->assertDontSee('persoenlich.ics');
    }

    public function test_name_und_e_mail_bleiben_der_benutzerverwaltung(): void
    {
        $user = $this->userWith($this->role('catering', ['events' => 'read']));
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm(['email' => 'gekapert@example.org', 'first_name' => 'Anders'])
            ->call('save');

        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame($user->first_name, $user->fresh()->first_name);
    }
}
