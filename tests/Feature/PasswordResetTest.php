<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Models\AuditLog;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        // Die Anfrage wartet sonst jedes Mal 1,5 Sekunden (Timebox)
        Sleep::fake();
    }

    public function test_anmeldeseite_hat_passwort_vergessen(): void
    {
        $this->get('/login')->assertOk()->assertSee('Passwort vergessen?');
    }

    public function test_link_nur_fuer_aktive_konten_und_ohne_zu_verraten_ob_es_die_adresse_gibt(): void
    {
        Notification::fake();
        $aktiv = $this->admin();
        $gesperrt = $this->userWith($this->role('catering', ['kalender' => 'read']));
        $gesperrt->update(['is_active' => false]);

        // Bekannt, gleich noch einmal (gebremst), gesperrt, unbekannt: überall dieselbe Antwort
        foreach ([$aktiv->email, $aktiv->email, $gesperrt->email, 'niemand@example.org'] as $i => $email) {
            Livewire::test(RequestPasswordReset::class)
                ->fillForm(['email' => $email])
                ->call('request')
                ->assertNotified(__('passwords.sent'))
                ->assertSet('data.email', null);
            // Filament lässt nur zwei Anfragen je Minute zu
            if ($i === 1) {
                $this->travel(2)->minutes();
            }
        }

        Notification::assertSentToTimes($aktiv, ResetPasswordNotification::class, 1);
        Notification::assertNotSentTo($gesperrt, ResetPasswordNotification::class);
    }

    public function test_neues_passwort_setzen_ohne_hash_im_protokoll(): void
    {
        $user = $this->admin();
        $token = Password::broker()->createToken($user);

        Livewire::withQueryParams(['email' => $user->email, 'token' => $token])
            ->test(ResetPassword::class)
            ->fillForm(['password' => 'Neues-Passwort-2026', 'passwordConfirmation' => 'Neues-Passwort-2026'])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        $hash = (string) $user->fresh()->password;
        $this->assertTrue(Hash::check('Neues-Passwort-2026', $hash));
        $this->assertStringNotContainsString($hash, AuditLog::query()->get()->toJson());
    }
}
