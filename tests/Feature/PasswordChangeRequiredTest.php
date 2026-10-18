<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\ChangePassword;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use App\Support\AuditPresenter;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/** Passwort vom Admin vergeben: beim nächsten Anmelden ein eigenes festlegen (RequirePasswordChange, ChangePassword). */
class PasswordChangeRequiredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    /** Konto mit einem Passwort, das ein Admin vergeben hat */
    private function member(string $slug = 'team'): User
    {
        $user = $this->userWith($this->role($slug, ['events' => 'read', 'kalender' => 'read']));
        $user->forceFill(['password' => 'Vergeben-2026-Start', 'must_change_password' => true])->save();

        return $user->fresh();
    }

    public function test_admin_vergibt_passwort_dann_muss_es_ersetzt_werden(): void
    {
        $admin = $this->admin();
        $role = $this->role('team', ['kalender' => 'read']);
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm(['last_name' => 'Neu', 'email' => 'neu@example.org', 'password' => 'Start-Passwort-1', 'roles' => [$role->id], 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(User::query()->where('email', 'neu@example.org')->sole()->must_change_password);

        // Bearbeiten ohne Passwort ändert nichts; ein neues für jemand anderen setzt die Pflicht
        $other = $this->userWith($role);
        Livewire::test(EditUser::class, ['record' => $other->getRouteKey()])
            ->fillForm(['phone' => '040 123'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse($other->fresh()->must_change_password);
        Livewire::test(EditUser::class, ['record' => $other->getRouteKey()])
            ->fillForm(['password' => 'Neues-Passwort-2'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($other->fresh()->must_change_password);

        // Das eigene Passwort hat man selbst gewählt
        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm(['password' => 'Eigenes-Passwort-3'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse($admin->fresh()->must_change_password);

        $log = AuditLog::query()->where('subject', 'users')->where('subject_key', (string) $other->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('Muss Passwort ändern', AuditPresenter::field('users', 'must_change_password'));
        $this->assertSame('ja', AuditPresenter::value('must_change_password', $log->new_values['must_change_password']));
        $this->assertSame('***', $log->new_values['password']);
    }

    public function test_vorher_nur_die_pflichtseite(): void
    {
        $user = $this->member();
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
        $this->actingAs($user);

        $this->get('/events')->assertRedirect(route(ChangePassword::ROUTE));
        $this->get('/profile')->assertRedirect(route(ChangePassword::ROUTE));
        // Auch Druckansichten und Downloads außerhalb des Panels
        $this->get(route('events.guest-list-print', $event))->assertRedirect(route(ChangePassword::ROUTE));
        $this->get(route(ChangePassword::ROUTE))
            ->assertOk()
            ->assertSee('Neues Passwort festlegen')
            ->assertSee('Ihr bisheriges Passwort hat die Verwaltung vergeben.');

        // Abmelden geht
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_neues_passwort_festlegen_und_weiter_zur_angefragten_seite(): void
    {
        $user = $this->member();
        $this->actingAs($user);
        $this->get('/kalender')->assertRedirect(route(ChangePassword::ROUTE));

        // Nicht das bisherige, nicht zu kurz, zweimal gleich
        foreach ([['Vergeben-2026-Start', 'Vergeben-2026-Start'], ['kurz', 'kurz'], ['Mein-eigenes-2026', 'anders-2026']] as [$password, $confirmation]) {
            Livewire::test(ChangePassword::class)
                ->fillForm(['password' => $password, 'passwordConfirmation' => $confirmation])
                ->call('save')
                ->assertHasFormErrors(['password']);
        }
        $this->assertTrue($user->fresh()->must_change_password);

        Livewire::test(ChangePassword::class)
            ->fillForm(['password' => 'Mein-eigenes-2026', 'passwordConfirmation' => 'Mein-eigenes-2026'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(url('/kalender'));

        $fresh = $user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue(Hash::check('Mein-eigenes-2026', $fresh->password));
        $this->get('/kalender')->assertOk();
        // Ohne Pflicht führt die Seite weiter
        $this->get(route(ChangePassword::ROUTE))->assertRedirect(Filament::getUrl());
    }

    public function test_eigenes_passwort_im_profil_oder_ueber_passwort_vergessen_erfuellt_die_pflicht(): void
    {
        $user = $this->member();
        $this->actingAs($user);
        Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'Profil-Passwort-9', 'passwordConfirmation' => 'Profil-Passwort-9', 'currentPassword' => 'Vergeben-2026-Start'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse($user->fresh()->must_change_password);

        $other = $this->member('team2');
        Auth::logout();
        $token = Password::broker()->createToken($other);
        Livewire::withQueryParams(['email' => $other->email, 'token' => $token])
            ->test(ResetPassword::class)
            ->fillForm(['password' => 'Reset-Passwort-7', 'passwordConfirmation' => 'Reset-Passwort-7'])
            ->call('resetPassword')
            ->assertHasNoFormErrors();
        $this->assertFalse($other->fresh()->must_change_password);
        $this->assertTrue(Hash::check('Reset-Passwort-7', $other->fresh()->password));
    }

    public function test_ohne_pflicht_keine_umleitung(): void
    {
        $this->actingAs($this->admin());

        $this->get('/events')->assertOk();
        $this->get(route(ChangePassword::ROUTE))->assertRedirect(Filament::getUrl());
    }
}
