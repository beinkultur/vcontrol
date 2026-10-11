<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageSystem;
use App\Mail\BackupFailed;
use App\Models\BackupRun;
use App\Models\Event;
use App\Models\Setting;
use App\Support\Backups;
use App\Support\DatabaseDump;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    /** @var array<string, string> hochgeladene Dateien: Name => Inhalt */
    private array $uploads = [];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Http::preventStrayRequests();
        Mail::fake();
        Storage::fake('local');
        config([
            'app.url' => 'https://ipa.vcontrol.eu',
            'venuecontrol.backup.share_url' => 'https://cloud.example.org/s/AbC123xyz',
            'venuecontrol.backup.share_password' => 'geheim#1',
        ]);
    }

    /** Nextcloud antwortet mit diesem Status und merkt sich, was ankam. */
    private function nextcloud(int $status = 201): void
    {
        Http::fake(function (Request $request) use ($status) {
            $this->uploads[rawurldecode(basename((string) parse_url($request->url(), PHP_URL_PATH)))] = $request->body();

            return Http::response('', $status);
        });
    }

    public function test_dump_mit_struktur_und_daten_ohne_sitzungen(): void
    {
        Event::create(['title' => "Konzert mit 'Anführungszeichen'", 'starts_at' => now()]);
        DB::table('sessions')->insert(['id' => 'sitzung', 'payload' => 'geheim', 'last_activity' => time()]);
        $path = tempnam(sys_get_temp_dir(), 'vc-dump');

        DatabaseDump::toFile($path);
        $sql = (string) gzdecode((string) file_get_contents($path));
        unlink($path);

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO `events`', $sql);
        $this->assertStringContainsString("'Konzert mit ''Anführungszeichen'''", $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `sessions`', $sql);
        $this->assertStringNotContainsString('INSERT INTO `sessions`', $sql);
    }

    public function test_datenbank_und_dateien_landen_in_der_datei_ablage(): void
    {
        $this->nextcloud();
        Event::create(['title' => 'Sommerfest', 'starts_at' => now()]);
        Storage::disk('local')->put('event-files/rider.pdf', '%PDF-1.4 Rider');
        Storage::disk('local')->put('livewire-tmp/zwischen.tmp', 'Zwischenstand');

        $runs = Backups::run('manuell', withFiles: true);

        $this->assertSame([BackupRun::OK, BackupRun::OK], $runs->pluck('status')->all());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_starts_with($request->url(), 'https://cloud.example.org/public.php/dav/files/AbC123xyz/ipa.vcontrol.eu-datenbank-')
            && $request->hasHeader('Authorization', 'Basic ' . base64_encode('anonymous:geheim#1')));

        $database = collect($this->uploads)->first(fn (string $body, string $name): bool => str_ends_with($name, '.sql.gz'));
        $this->assertStringContainsString('Sommerfest', (string) gzdecode((string) $database));

        $zipPath = tempnam(sys_get_temp_dir(), 'vc-zip');
        file_put_contents($zipPath, collect($this->uploads)->first(fn (string $body, string $name): bool => str_ends_with($name, '.zip')));
        $zip = new ZipArchive();
        $zip->open($zipPath);
        $this->assertSame('%PDF-1.4 Rider', $zip->getFromName('event-files/rider.pdf'));
        $this->assertFalse($zip->locateName('livewire-tmp/zwischen.tmp'));
        $this->assertNotFalse($zip->locateName('LIESMICH.txt'));
        $zip->close();
        unlink($zipPath);

        // Arbeitsdateien sind wieder weg, Mail gibt es nur bei Fehlern
        $this->assertSame([], glob(storage_path('app/backup-tmp/ipa.vcontrol.eu-*')));
        $this->assertNull(Backups::warning());
        Mail::assertNothingSent();
    }

    public function test_dateien_sonntags_und_wenn_die_letzte_aelter_als_eine_woche_ist(): void
    {
        $this->nextcloud();
        $this->travelTo(now()->next('Monday')->setTime(3, 30));
        $this->assertTrue(Backups::filesDue(), 'noch nie gesichert');
        $this->assertCount(2, Backups::run('zeitplan'));

        $this->travel(1)->days();
        $this->assertFalse(Backups::filesDue());
        $this->assertCount(1, Backups::run('zeitplan'));

        $this->travelTo(now()->next('Sunday')->setTime(3, 30));
        $this->assertTrue(Backups::filesDue());
    }

    public function test_fehler_per_mail_an_die_system_admins_ohne_schluessel_des_links(): void
    {
        $system = $this->systemAdmin();
        $admin = $this->admin();
        $this->nextcloud(401);

        $runs = Backups::run('zeitplan', withFiles: false);

        $run = $runs->first();
        $this->assertSame(BackupRun::FAILED, $run->status);
        $this->assertStringContainsString('401', $run->error);
        $this->assertStringNotContainsString('AbC123xyz', $run->error);
        Mail::assertSent(BackupFailed::class, fn (BackupFailed $mail): bool => $mail->hasTo($system->email));
        Mail::assertNotSent(BackupFailed::class, fn (BackupFailed $mail): bool => $mail->hasTo($admin->email));
        $this->assertStringContainsString('fehlgeschlagen', (string) Backups::warning());
    }

    public function test_ohne_einrichtung_kein_upload(): void
    {
        config(['venuecontrol.backup.share_password' => null]);

        $runs = Backups::run('konsole', withFiles: false);

        $this->assertSame(BackupRun::FAILED, $runs->first()->status);
        $this->assertStringContainsString('VC_BACKUP_SHARE_PASSWORD', $runs->first()->error);
        Http::assertNothingSent();
    }

    public function test_cronjob_nur_mit_schluessel_und_ohne_sitzung(): void
    {
        $this->nextcloud();
        $this->get('/cron/sicherung?key=')->assertForbidden();

        Backups::newCronKey();
        $this->get('/cron/sicherung?key=falsch')->assertForbidden();
        $this->get('/cron/sicherung?key=' . Setting::lookup(Setting::BACKUP_CRON_KEY))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('OK Datenbank: ipa.vcontrol.eu-datenbank-', false);

        $this->assertSame('zeitplan', BackupRun::query()->latest('id')->value('trigger'));
        // Sonst stünde jede Nacht eine Zeile in `sessions`
        $this->assertContains(StartSession::class, Route::getRoutes()->getByName('backup.cron')->excludedMiddleware());
    }

    public function test_seite_system_zeigt_stand_und_sichert_von_hand(): void
    {
        $this->nextcloud();
        $this->actingAs($this->admin())->get('/system')->assertForbidden();

        $this->actingAs($this->systemAdmin());
        $this->get('/system')
            ->assertOk()
            ->assertSee('Datei-Ablage auf cloud.example.org')
            ->assertDontSee('AbC123xyz')
            ->assertSee('Es gibt noch keine Sicherung');

        Livewire::test(ManageSystem::class)
            ->callAction(TestAction::make('newCronKey')->schemaComponent('backupActions', 'content'))
            ->callAction(TestAction::make('backupNow')->schemaComponent('backupActions', 'content'))
            ->assertNotified('Gesichert');

        $key = (string) Setting::lookup(Setting::BACKUP_CRON_KEY);
        $this->assertSame(40, strlen($key));
        $this->assertSame(2, BackupRun::query()->where('status', BackupRun::OK)->where('trigger', 'manuell')->count());
        $this->get('/system')->assertOk()->assertSee('/cron/sicherung?key=' . $key, false)->assertDontSee('Es gibt noch keine Sicherung');
    }
}
