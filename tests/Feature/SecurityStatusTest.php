<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Role;
use App\Models\User;
use App\Support\SecurityStatus;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'venuecontrol.backup.share_url' => 'https://cloud.example.org/s/AbC123xyz',
            'venuecontrol.backup.share_password' => 'geheim',
        ]);
        BackupRun::query()->create(['kind' => BackupRun::DATABASE, 'status' => BackupRun::OK, 'trigger' => 'zeitplan', 'started_at' => now()->subHours(3)]);
    }

    /** @return array<string, array{check: string, severity: string, title: string}> */
    private function findings(): array
    {
        return collect(SecurityStatus::findings())->keyBy('check')->all();
    }

    public function test_ohne_auffaelligkeiten_keine_befunde(): void
    {
        $this->assertSame([], SecurityStatus::findings());
    }

    public function test_ausgebliebene_sicherung_ist_hoch(): void
    {
        BackupRun::query()->update(['started_at' => now()->subHours(40)]);
        $this->assertSame('high', $this->findings()['sicherung']['severity']);

        config(['venuecontrol.backup.share_url' => null]);
        $this->assertStringContainsString('Keine Sicherung eingerichtet', $this->findings()['sicherung']['title']);
    }

    public function test_neuer_vollzugriff_der_woche_ist_hoch(): void
    {
        $this->actingAs($this->systemAdmin());
        $neu = $this->userWith();
        $neu->roles()->attach(Role::query()->firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_super' => true])->id);

        $befund = $this->findings()['vollzugriff'];
        $this->assertSame('high', $befund['severity']);
        $this->assertStringContainsString($neu->getFilamentName().' · Admin', $befund['title']);

        // Nach einer Woche ist es kein neuer Befund mehr
        $this->travel(8)->days();
        BackupRun::query()->update(['started_at' => now()->subHours(3)]);
        $this->assertArrayNotHasKey('vollzugriff', $this->findings());
    }

    public function test_geaenderte_rollenrechte_sind_mittel(): void
    {
        $rolle = $this->role('kasse', ['events' => 'read']);
        $rolle->update(['permissions' => ['events' => 'edit']]);

        $this->assertSame('medium', $this->findings()['rechte']['severity']);
        $this->assertStringContainsString('Kasse', $this->findings()['rechte']['title']);
    }

    public function test_ungenutzte_konten_und_die_anmeldung_wird_ohne_protokoll_erfasst(): void
    {
        $alt = $this->userWith();
        $alt->forceFill(['last_login_at' => now()->subDays(120)])->saveQuietly();
        $aktiv = $this->userWith();
        $count = \App\Models\AuditLog::query()->count();

        Event::dispatch(new Login('web', $aktiv, false));

        $this->assertNotNull($aktiv->fresh()->last_login_at);
        $this->assertSame($count, \App\Models\AuditLog::query()->count(), 'Anmeldungen gehören nicht ins Änderungsprotokoll.');
        $befund = $this->findings()['ungenutzt'];
        $this->assertStringContainsString($alt->getFilamentName(), $befund['title']);
        $this->assertStringNotContainsString($aktiv->getFilamentName(), $befund['title']);

        // „Nie angemeldet“ zählt erst, wenn die Erfassung 90 Tage läuft
        $nie = $this->userWith();
        $nie->forceFill(['created_at' => now()->subYear()])->saveQuietly();
        $this->assertStringNotContainsString($nie->getFilamentName(), $this->findings()['ungenutzt']['title']);
        $this->travelTo(now()->parse(SecurityStatus::LOGINS_SINCE)->addDays(91));
        BackupRun::query()->update(['started_at' => now()->subHours(3)]);
        $this->assertStringContainsString($nie->getFilamentName(), $this->findings()['ungenutzt']['title']);
    }

    public function test_ausfuehrbare_dateien_in_der_upload_zwischenablage(): void
    {
        $disk = Storage::disk('local');
        $disk->put('livewire-tmp/abc.exe', 'MZ');
        $disk->put('livewire-tmp/abc.exe.json', json_encode(['name' => 'Spotify.exe']));
        touch($disk->path('livewire-tmp/abc.exe'), now()->subDays(3)->getTimestamp());
        $disk->put('livewire-tmp/frisch.pdf', '%PDF');

        $befund = $this->findings()['uploads'];
        $this->assertSame('medium', $befund['severity']);
        $this->assertStringContainsString('Spotify.exe', $befund['title']);
    }

    public function test_zahlen_fuer_den_wochenbericht(): void
    {
        $system = $this->systemAdmin();
        $this->admin();

        $facts = implode("\n", SecurityStatus::facts());

        $this->assertStringContainsString($system->getFilamentName().' (System-Admin)', $facts);
        $this->assertStringContainsString('Gültige Daysheet-Links: 0', $facts);
        $this->assertMatchesRegularExpression('/Letzte 7 Tage: \d+ Konto\/Konten angelegt/', $facts);
    }
}
