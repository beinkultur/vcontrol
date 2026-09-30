<?php

namespace Tests\Feature;

use App\Access\AccountSafety;
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
        $verwalter = $this->userWith($this->role('verwalter', ['admin_benutzer' => 'edit']));
        $kollege = $this->userWith($this->role('catering', ['kalender' => 'read']));
        $this->actingAs($verwalter);

        $this->assertNotNull(AccountSafety::violation($kollege, [$adminRolle->id], true));
        $this->assertNull(AccountSafety::violation($kollege, $kollege->roles->pluck('id')->all(), true));
    }

    public function test_admin_konten_aendern_nur_admins(): void
    {
        $admin = $this->admin();
        $verwalter = $this->userWith($this->role('verwalter', ['admin_benutzer' => 'edit']));
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
}
