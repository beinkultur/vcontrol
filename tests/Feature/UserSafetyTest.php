<?php

namespace Tests\Feature;

use App\Access\AccountSafety;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Users\Pages\EditUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class UserSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_letzter_admin_behaelt_rolle_und_bleibt_aktiv(): void
    {
        $admin = $this->admin();
        $catering = $this->role('catering', ['kalender' => 'read']);
        $this->actingAs($admin);

        $this->assertNotNull(AccountSafety::violation($admin, [$catering->id], true));
        $this->assertNotNull(AccountSafety::violation($admin, $admin->roles->pluck('id')->all(), false));
    }

    public function test_formular_haelt_beim_entzug_der_letzten_admin_rolle_an(): void
    {
        $admin = $this->admin();
        $catering = $this->role('catering', ['kalender' => 'read']);
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm(['roles' => [$catering->id]])
            ->call('save');

        $this->assertTrue($admin->fresh()->access()->isSuper());
    }

    public function test_zweiter_admin_darf_ersten_herabstufen(): void
    {
        $erster = $this->admin();
        $zweiter = $this->admin();
        $catering = $this->role('catering', ['kalender' => 'read']);
        $this->actingAs($zweiter);

        $this->assertNull(AccountSafety::violation($erster, [$catering->id], true));
    }

    public function test_nur_admins_vergeben_die_admin_rolle(): void
    {
        $adminRolle = $this->role('admin', super: true);
        $verwalter = $this->userWith($this->role('verwalter', ['admin_benutzer' => 'edit', 'kalender' => 'read']));
        $kollege = $this->userWith($this->role('catering', ['kalender' => 'read']));
        $this->actingAs($verwalter);

        $this->assertNotNull(AccountSafety::violation($kollege, [$adminRolle->id], true));
        $this->assertNull(AccountSafety::violation($kollege, $kollege->roles->pluck('id')->all(), true));
    }

    public function test_admin_konten_aendern_nur_admins(): void
    {
        $admin = $this->admin();
        $verwalter = $this->userWith($this->role('verwalter', ['admin_benutzer' => 'edit', 'kalender' => 'read']));
        $kollege = $this->userWith($this->role('catering', ['kalender' => 'read']));

        $this->assertFalse(Gate::forUser($verwalter)->allows('update', $admin));
        $this->assertTrue(Gate::forUser($verwalter)->allows('update', $kollege));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $kollege));
    }

    public function test_niemand_loescht_sich_selbst_oder_den_letzten_admin(): void
    {
        $admin = $this->admin();
        $kollege = $this->userWith($this->role('catering', ['kalender' => 'read']));

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $admin));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $kollege));

        $zweiter = $this->admin();
        $this->assertTrue(Gate::forUser($zweiter)->allows('delete', $admin), 'mit zwei Admins darf einer den anderen löschen');
    }

    public function test_admin_rolle_aendern_nur_admins(): void
    {
        $adminRolle = $this->role('admin', super: true);
        $catering = $this->role('catering', ['kalender' => 'read']);
        $rollenpfleger = $this->userWith($this->role('rollen', ['admin_rollen' => 'edit', 'kalender' => 'read']));

        $this->assertFalse(Gate::forUser($rollenpfleger)->allows('update', $adminRolle));
        $this->assertTrue(Gate::forUser($rollenpfleger)->allows('update', $catering));
        $this->assertTrue(Gate::forUser($this->admin())->allows('update', $adminRolle));

        $this->actingAs($rollenpfleger)->get('/rollen/' . $adminRolle->id . '/edit')->assertForbidden();
    }

    public function test_nie_mehr_rechte_vergeben_als_man_selbst_hat(): void
    {
        $planer = $this->role('planer', ['events' => 'edit']);
        $kasse = $this->role('kasse', ['events' => 'read', 'buchhaltung' => 'edit']);
        $verwalter = $this->userWith($this->role('verwalter', ['admin_benutzer' => 'edit', 'admin_rollen' => 'edit', 'events' => 'edit']));
        $kollege = $this->userWith($planer);
        $buchhalterin = $this->userWith($kasse);
        $this->actingAs($verwalter);

        // Rollen vergeben: nur innerhalb der eigenen Rechte – auch nicht sich selbst
        $this->assertNull(AccountSafety::violation($kollege, [$planer->id], true));
        $this->assertNotNull(AccountSafety::violation($kollege, [$planer->id, $kasse->id], true));
        $this->assertNotNull(AccountSafety::violation($verwalter, [...$verwalter->roles->pluck('id')->all(), $kasse->id], true));
        // Kalender-Rechte am Konto ebenso
        $this->assertNotNull(AccountSafety::violation($kollege, [$planer->id], true, ['towers' => 'edit']));

        // Konten mit mehr Rechten (Passwort setzen = sich deren Rechte nehmen) nur mit diesen Rechten
        $this->assertTrue(Gate::forUser($verwalter)->allows('update', $kollege));
        $this->assertFalse(Gate::forUser($verwalter)->allows('update', $buchhalterin));
        $this->assertFalse(Gate::forUser($verwalter)->allows('delete', $buchhalterin));

        // Rollen bearbeiten: nur, wenn man deren Rechte selbst hat …
        $this->assertTrue(Gate::forUser($verwalter)->allows('update', $planer));
        $this->assertFalse(Gate::forUser($verwalter)->allows('update', $kasse));

        // … und beim Speichern nichts darüber hinaus, auch nicht in der eigenen Rolle
        $eigene = $verwalter->roles->first();
        Livewire::test(EditRole::class, ['record' => $eigene->getRouteKey()])
            ->fillForm(['permissions' => [...$eigene->permissions, 'buchhaltung' => 'edit']])
            ->call('save');
        $this->assertArrayNotHasKey('buchhaltung', $eigene->fresh()->permissions);

        Livewire::test(EditRole::class, ['record' => $planer->getRouteKey()])
            ->fillForm(['permissions' => ['events' => 'read']])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('read', $planer->fresh()->permissions['events']);

        // Admins dürfen alles
        $this->actingAs($this->admin());
        $this->assertNull(AccountSafety::violation($kollege, [$planer->id, $kasse->id], true, ['towers' => 'edit']));
    }
}
