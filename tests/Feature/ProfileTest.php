<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\AuditLog;
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
