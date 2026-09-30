<?php

namespace Tests\Feature;

use App\Access\Area;
use App\Access\Level;
use App\Models\Calendar;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessTest extends TestCase
{
    public function test_rollen_addieren_sich_je_bereich_zaehlt_die_hoechste_stufe(): void
    {
        $user = $this->userWith(
            $this->role('a', ['events' => 'read', 'buchhaltung' => 'edit']),
            $this->role('b', ['events' => 'edit', 'buchhaltung' => 'none']),
        );

        $this->assertSame(Level::Edit, $user->access()->level(Area::Events));
        $this->assertSame(Level::Edit, $user->access()->level(Area::Buchhaltung));
        $this->assertSame(Level::None, $user->access()->level(Area::Audit));
    }

    public function test_admin_darf_alles_ausser_abgeschalteten_modulen(): void
    {
        config(['venuecontrol.disabled_areas' => ['schichten']]);
        $admin = $this->admin();

        $this->assertTrue($admin->access()->canEdit(Area::AdminRollen));
        $this->assertTrue($admin->access()->canEdit(Area::Buchhaltung));
        $this->assertFalse($admin->access()->can(Area::Schichten));
    }

    public function test_abgeschaltetes_modul_ist_auch_mit_recht_zu(): void
    {
        config(['venuecontrol.disabled_areas' => ['zaehler']]);
        $user = $this->userWith($this->role('technik', ['zaehler' => 'edit']));

        $this->assertFalse($user->access()->can(Area::Zaehler));
    }

    public function test_kalenderrechte_aus_rolle_und_benutzer(): void
    {
        $user = $this->userWith($this->role('a', ['kalender' => 'read'], calendars: ['events' => 'read']));
        $user->update(['calendar_permissions' => ['events' => 'edit', 'wartung' => 'read']]);
        $user = $user->fresh();

        $this->assertSame(Level::Edit, $user->access()->calendarLevel('events'));
        $this->assertSame(Level::Read, $user->access()->calendarLevel('wartung'));
        $this->assertSame(Level::None, $user->access()->calendarLevel('towers'));
    }

    public function test_persoenlicher_kalender_nur_mit_kalenderzugriff(): void
    {
        $mit = $this->userWith($this->role('mit', ['kalender' => 'read']));
        $ohne = $this->userWith($this->role('ohne', ['events' => 'read']));

        $this->assertTrue($mit->access()->canCalendar(Calendar::PERSONAL));
        $this->assertFalse($ohne->access()->canCalendar(Calendar::PERSONAL));
    }

    public function test_ohne_anmeldung_geht_es_zur_anmeldung(): void
    {
        $this->get('/veranstalter')->assertRedirect('/login');
    }

    public function test_extern_kommt_nicht_in_den_arbeitsbereich(): void
    {
        $extern = $this->userWith($this->role('extern', ['events_extern' => 'read']));

        $this->actingAs($extern)->get('/')->assertForbidden();
    }

    public function test_inaktive_konten_kommen_nicht_hinein(): void
    {
        $admin = $this->admin();
        $admin->update(['is_active' => false]);

        $this->actingAs($admin->fresh())->get('/')->assertForbidden();
    }

    /** @return array<string, array{string, string}> */
    public static function pages(): array
    {
        return [
            'Veranstalter' => ['/veranstalter', 'veranstalter'],
            'Benutzer' => ['/benutzer', 'admin_benutzer'],
            'Rollen' => ['/rollen', 'admin_rollen'],
            'Gewerke' => ['/gewerke', 'admin_gewerke'],
            'Mitarbeiter' => ['/mitarbeiter', 'admin_mitarbeiter'],
            'Räume' => ['/raeume', 'admin_raeume'],
            'Feldoptionen' => ['/feldoptionen', 'admin_feldoptionen'],
            'Inventar' => ['/inventar', 'admin_inventar'],
            'Inventar-Kategorien' => ['/inventar-kategorien', 'admin_inventar'],
            'Kalender-Ebenen' => ['/kalender-ebenen', 'admin_kalender'],
            'Halle' => ['/halle', 'admin_stammdaten'],
        ];
    }

    #[DataProvider('pages')]
    public function test_seite_nur_mit_recht_auf_ihren_bereich(string $path, string $area): void
    {
        // Ein anderes Recht, damit der Benutzer überhaupt in den Arbeitsbereich darf
        $leser = $this->userWith($this->role('leser', [$area => 'read']));
        $fremd = $this->userWith($this->role('fremd', ['audit' => 'read']));

        $this->actingAs($leser)->get($path)->assertOk();
        $this->actingAs($fremd)->get($path)->assertForbidden();
    }
}
