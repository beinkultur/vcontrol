<?php

namespace Tests\Feature;

use App\Access\AccountSafety;
use App\Access\Area;
use App\Access\Modules;
use App\Filament\Pages\ManageSystem;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * System-Admin (Betreiber) über dem Admin der Halle: Module, Rollen & Rechte
 * und die eigenen Konten, die sonst niemand sieht.
 */
class SystemAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_befehl_macht_ein_konto_zum_system_admin(): void
    {
        $user = User::factory()->create(['email' => 'betreiber@example.org']);

        $this->artisan('vc:system-admin', ['email' => 'betreiber@example.org'])->assertSuccessful();
        $this->artisan('vc:system-admin', ['email' => 'betreiber@example.org'])->expectsOutputToContain('schon System-Admin');
        $this->artisan('vc:system-admin', ['email' => 'niemand@example.org'])
            ->expectsConfirmation('Kein Konto mit niemand@example.org. Anlegen?', 'no')
            ->assertFailed();
        $this->artisan('vc:system-admin', ['email' => 'niemand@example.org', '--no-interaction' => true])->assertFailed();
        $this->assertNull(User::query()->where('email', 'niemand@example.org')->first());

        $this->assertTrue($user->fresh()->access()->isSystemAdmin());
        $this->assertTrue($user->fresh()->access()->isSuper());
        $this->assertSame(1, Role::query()->where('is_system_admin', true)->count());
    }

    public function test_befehl_legt_den_ersten_system_admin_an_passwort_verdeckt(): void
    {
        // Neue Installation ohne Import: das Konto entsteht über die Konsole
        $this->artisan('vc:system-admin', ['email' => 'Betreiberin@Example.org'])
            ->expectsConfirmation('Kein Konto mit betreiberin@example.org. Anlegen?', 'yes')
            ->expectsQuestion('Vorname', 'Nina')
            ->expectsQuestion('Nachname', 'Neu')
            ->expectsQuestion('Passwort (mindestens 8 Zeichen)', 'lang-genug-2026')
            ->expectsQuestion('Passwort wiederholen', 'lang-genug-2026')
            ->expectsOutputToContain('Nina Neu ist jetzt System-Admin')
            ->assertSuccessful();

        $user = User::query()->where('email', 'betreiberin@example.org')->sole();
        $this->assertTrue(Hash::check('lang-genug-2026', (string) $user->password));
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->access()->isSystemAdmin());

        // Zu kurz oder vertippt: kein Konto
        $this->artisan('vc:system-admin', ['email' => 'kurz@example.org'])
            ->expectsConfirmation('Kein Konto mit kurz@example.org. Anlegen?', 'yes')
            ->expectsQuestion('Vorname', 'K')
            ->expectsQuestion('Nachname', 'Urz')
            ->expectsQuestion('Passwort (mindestens 8 Zeichen)', '1234567')
            ->assertFailed();
        $this->artisan('vc:system-admin', ['email' => 'vertippt@example.org'])
            ->expectsConfirmation('Kein Konto mit vertippt@example.org. Anlegen?', 'yes')
            ->expectsQuestion('Vorname', 'V')
            ->expectsQuestion('Nachname', 'Ertippt')
            ->expectsQuestion('Passwort (mindestens 8 Zeichen)', 'lang-genug-2026')
            ->expectsQuestion('Passwort wiederholen', 'lang-genug-2027')
            ->assertFailed();
        $this->assertSame(0, User::query()->whereIn('email', ['kurz@example.org', 'vertippt@example.org'])->count());
    }

    public function test_hallen_admin_sieht_system_admins_nicht(): void
    {
        $system = $this->systemAdmin();
        $admin = $this->admin();
        $kollege = $this->userWith($this->role('catering', ['kalender' => 'read']));

        $this->assertFalse(Gate::forUser($admin)->allows('view', $system));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $system));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $system));
        $this->assertFalse(Gate::forUser($admin)->allows('view', Role::systemAdmin()));

        $this->actingAs($admin);
        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$admin, $kollege])
            ->assertCanNotSeeTableRecords([$system]);
        $this->get('/rollen')->assertForbidden();
        $this->get('/benutzer/' . $system->id . '/edit')->assertNotFound();
        $this->get('/rollen/' . Role::systemAdmin()->id)->assertNotFound();
        // Auch nicht als Rolle zur Auswahl
        Livewire::test(EditUser::class, ['record' => $kollege->getRouteKey()])
            ->assertFormFieldExists('roles', fn (CheckboxList $field): bool => !array_key_exists(Role::systemAdmin()->id, $field->getOptions()));

        // Der System-Admin sieht und ändert alle
        $this->assertTrue(Gate::forUser($system)->allows('update', $admin));
        $this->actingAs($system);
        Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$system, $admin, $kollege]);
        Livewire::test(EditUser::class, ['record' => $kollege->getRouteKey()])
            ->assertFormFieldExists('roles', fn (CheckboxList $field): bool => array_key_exists(Role::systemAdmin()->id, $field->getOptions()));
    }

    public function test_system_admin_rolle_vergeben_nur_system_admins(): void
    {
        $systemRolle = Role::systemAdmin();
        $kollege = $this->userWith($this->role('catering', ['kalender' => 'read']));

        $this->actingAs($this->admin());
        $this->assertNotNull(AccountSafety::violation($kollege, [$systemRolle->id], true));
        Livewire::test(EditUser::class, ['record' => $kollege->getRouteKey()])
            ->fillForm(['roles' => [$systemRolle->id]])
            ->call('save');
        $this->assertFalse($kollege->fresh()->access()->isSystemAdmin());

        $this->actingAs($this->systemAdmin());
        $this->assertNull(AccountSafety::violation($kollege, [$systemRolle->id], true));
    }

    public function test_letzter_system_admin_bleibt_system_admin(): void
    {
        $system = $this->systemAdmin();
        $this->admin();
        $catering = $this->role('catering', ['kalender' => 'read']);
        $this->actingAs($system);

        $this->assertNotNull(AccountSafety::violation($system, [$catering->id], true));
        $this->assertNotNull(AccountSafety::violation($system, [Role::systemAdmin()->id], false));
        $this->assertFalse(Gate::forUser($system)->allows('delete', $system));

        $zweiter = $this->systemAdmin();
        $this->assertNull(AccountSafety::violation($system, [$catering->id], true));
        $this->assertTrue(Gate::forUser($zweiter)->allows('delete', $system));
    }

    public function test_audit_ohne_system_admin_konten_ip_und_browser(): void
    {
        $system = $this->systemAdmin();
        $assigned = AuditLog::query()->where('subject', 'role_user')->latest('id')->firstOrFail();
        $this->actingAs($system);
        Event::create(['title' => 'Konzert', 'starts_at' => now()]);
        $byOperator = AuditLog::query()->where('subject', 'events')->latest('id')->firstOrFail();
        $system->update(['first_name' => 'Betreiber']);
        $aboutOperator = AuditLog::query()->where('subject', 'users')->latest('id')->firstOrFail();
        $admin = $this->admin();
        $aboutAdmin = AuditLog::query()->where('subject', 'role_user')->latest('id')->firstOrFail();

        $this->assertTrue(AuditLogResource::showsTechnicalDetails());
        Livewire::test(ListAuditLogs::class)->assertCanSeeTableRecords([$assigned, $byOperator, $aboutOperator, $aboutAdmin]);

        // Was der System-Admin geändert hat, ja – Einträge über sein Konto nicht
        $this->actingAs($admin);
        $this->assertFalse(AuditLogResource::showsTechnicalDetails());
        Livewire::test(ListAuditLogs::class)
            ->assertCanSeeTableRecords([$byOperator, $aboutAdmin])
            ->assertCanNotSeeTableRecords([$assigned, $aboutOperator]);
    }

    public function test_module_schaltet_nur_der_system_admin(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/system')->assertForbidden();
        $this->actingAs($system = $this->systemAdmin())->get('/system')->assertOk();
        $this->assertTrue($admin->access()->can(Area::Codes));

        // Codes abschalten, Schichten (Vorgabe aus) anschalten
        $component = Livewire::test(ManageSystem::class);
        $module = $component->get('data.areas_arbeitsbereiche');
        $this->assertNotContains('schichten', $module);
        $component->fillForm(['areas_arbeitsbereiche' => [...array_values(array_diff($module, ['codes'])), 'schichten']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertContains('codes', explode(',', (string) Setting::lookup(Setting::DISABLED_AREAS)));
        $this->assertTrue(Area::Codes->isDisabled());
        $this->assertFalse(Area::Schichten->isDisabled());
        $this->assertFalse($admin->fresh()->access()->can(Area::Codes));
        $this->assertFalse($system->fresh()->access()->can(Area::Codes), 'abgeschaltet auch für den System-Admin');
        $this->actingAs($admin)->get('/codes')->assertForbidden();

        // Gespeichert gilt die Auswahl, nicht mehr die Server-Konfiguration
        config(['venuecontrol.disabled_areas' => ['zaehler']]);
        $this->assertFalse(Area::Zaehler->isDisabled());
        $this->assertTrue(Modules::current()->isStored());
    }

    public function test_benutzer_rollen_und_halle_bleiben_immer_an(): void
    {
        $this->actingAs($this->systemAdmin());

        Livewire::test(ManageSystem::class)
            ->fillForm(['areas_arbeitsbereiche' => [], 'areas_administration' => [], 'areas_extern' => []])
            ->call('save');

        $this->assertTrue(Area::Events->isDisabled());
        foreach ([Area::AdminBenutzer, Area::AdminRollen, Area::AdminStammdaten] as $area) {
            $this->assertFalse($area->isDisabled(), $area->label());
        }
    }
}
