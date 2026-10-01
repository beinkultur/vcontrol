<?php

namespace Tests\Feature;

use App\Filament\Resources\EventFileTags\Pages\ManageEventFileTags;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\FilesRelationManager;
use App\Http\Controllers\EventFileController;
use App\Models\Event;
use App\Models\EventFile;
use App\Models\EventFileTag;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class EventFilesTest extends TestCase
{
    private Event $event;

    private EventFileTag $rider;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(EventFile::DISK);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->event = Event::create(['title' => 'Kanonenfieber', 'starts_at' => now()->addWeek()]);
        $this->rider = EventFileTag::create(['name' => 'Technical Rider', 'sort_order' => 40]);
    }

    private function manager(string $page = EditEvent::class)
    {
        return Livewire::test(FilesRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => $page]);
    }

    public function test_hochladen_neue_version_loeschen(): void
    {
        $this->actingAs($admin = $this->admin());

        $this->manager()->callTableAction('create', data: [
            'tag_id' => $this->rider->id,
            'title' => '',
            'upload' => UploadedFile::fake()->create('Rider V1.pdf', 120, 'application/pdf'),
        ])->assertHasNoTableActionErrors();

        $file = $this->event->files()->firstOrFail();
        $this->assertSame('Rider V1.pdf', $file->original_name);
        $this->assertSame(1, $file->version);
        $this->assertNull($file->title);
        $this->assertSame($admin->getFilamentName(), $file->created_by_name);
        Storage::disk(EventFile::DISK)->assertExists($file->path);
        $firstPath = $file->path;

        $this->manager()->callTableAction('edit', $file, data: [
            'title' => 'Technical Rider',
            'upload' => UploadedFile::fake()->create('Rider V2.pdf', 140, 'application/pdf'),
        ])->assertHasNoTableActionErrors();

        $file->refresh();
        $this->assertSame(2, $file->version);
        $this->assertSame('Rider V2.pdf', $file->original_name);
        $this->assertSame('Technical Rider', $file->displayName());
        Storage::disk(EventFile::DISK)->assertMissing($firstPath);
        Storage::disk(EventFile::DISK)->assertExists($file->path);

        $this->manager()->callTableAction('delete', $file);
        $this->assertSame(0, $this->event->files()->count());
        Storage::disk(EventFile::DISK)->assertMissing($file->path);
    }

    public function test_nur_erlaubte_dateitypen(): void
    {
        $this->actingAs($this->userWith($this->role('technik', ['events' => 'edit'])));

        $this->manager()->callTableAction('create', data: [
            'tag_id' => $this->rider->id,
            'upload' => UploadedFile::fake()->create('virus.exe', 10),
        ])->assertHasTableActionErrors(['upload']);

        // Archive nur für Admins, wie in der PHP-Version
        $this->manager()->callTableAction('create', data: [
            'tag_id' => $this->rider->id,
            'upload' => UploadedFile::fake()->create('plaene.zip', 10, 'application/zip'),
        ])->assertHasTableActionErrors(['upload']);
        $this->assertSame(0, $this->event->files()->count());
    }

    public function test_herunterladen_nur_mit_recht_auf_das_event(): void
    {
        Storage::disk(EventFile::DISK)->put('event-files/1/rider.pdf', '%PDF-1.4 Testdatei');
        $file = $this->event->files()->create([
            'tag_id' => $this->rider->id, 'path' => 'event-files/1/rider.pdf', 'original_name' => 'Rider.pdf',
            'mime_type' => 'application/pdf', 'size' => 18, 'version' => 1,
        ]);

        $this->get($file->downloadUrl())->assertRedirect();

        $this->actingAs($this->userWith($this->role('buchhaltung', ['buchhaltung' => 'read'])));
        $this->get($file->downloadUrl())->assertForbidden();

        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        $response = $this->get($file->downloadUrl())->assertOk();
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Rider.pdf', (string) $response->headers->get('Content-Disposition'));

        // Leserolle sieht die Dateien, ändert aber nichts
        $this->manager(ViewEvent::class)
            ->assertCanSeeTableRecords([$file])
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $file)
            ->assertTableActionVisible('download', $file);
    }

    public function test_uebersicht_zeigt_dateien_nach_tag(): void
    {
        $this->event->files()->create([
            'tag_id' => $this->rider->id, 'path' => 'event-files/1/rider.pdf', 'original_name' => 'Kanonenfieber Technical Rider.pdf',
            'version' => 2, 'uploaded_at' => '2026-06-29 13:54:00',
        ]);

        $this->actingAs($this->admin())
            ->get(EventResource::getUrl('edit', ['record' => $this->event]))
            ->assertOk()
            ->assertSeeInOrder(['Dateien', 'Technical Rider', 'Kanonenfieber Technical Rider.pdf', 'Stand: 29.06.2026 13:54 · v2'])
            ->assertSee('Dateien verwalten');
    }

    public function test_tags_archivieren_statt_loeschen(): void
    {
        $this->actingAs($this->admin());
        $unbenutzt = EventFileTag::create(['name' => 'Plan', 'sort_order' => 20]);
        $this->event->files()->create(['tag_id' => $this->rider->id, 'path' => 'x.pdf', 'original_name' => 'x.pdf']);

        Livewire::test(ManageEventFileTags::class)
            ->assertTableActionHidden('delete', $this->rider)
            ->assertTableActionVisible('delete', $unbenutzt)
            ->callTableAction('archive', $this->rider);

        $this->assertTrue($this->rider->fresh()->is_archived);
        $this->assertArrayNotHasKey($this->rider->id, EventFileTag::options());
        $this->assertArrayHasKey($this->rider->id, EventFileTag::options($this->rider->id));

        // Tags pflegt nur, wer Events bearbeiten darf (PHP-Version: /dateien/tags)
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        $this->get('/datei-tags')->assertForbidden();
    }

    public function test_html_unter_anderer_endung_und_fremde_pfade_werden_abgewiesen(): void
    {
        $this->actingAs($this->userWith($this->role('technik', ['events' => 'edit'])));
        Storage::disk(EventFile::DISK)->put('damages/foto.jpg', 'jpeg');

        // HTML als „.pdf“ (im Test liefert Livewire den angegebenen statt des erkannten Typs)
        $this->manager()->callTableAction('create', data: [
            'tag_id' => $this->rider->id,
            'upload' => UploadedFile::fake()->create('plan.pdf', 1, 'text/html'),
        ])->assertHasTableActionErrors(['upload']);

        // Pfad einer vorhandenen fremden Datei statt eines Uploads
        $this->manager()->callTableAction('create', data: [
            'tag_id' => $this->rider->id,
            'upload' => ['damages/foto.jpg'],
        ])->assertHasTableActionErrors(['upload']);

        $this->assertSame(0, $this->event->files()->count());
        Storage::disk(EventFile::DISK)->assertExists('damages/foto.jpg');
    }

    public function test_im_browser_nur_mit_passendem_inhalt_und_in_der_sandbox(): void
    {
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        $file = function (string $name, string $mime, string $content): EventFile {
            Storage::disk(EventFile::DISK)->put('event-files/1/' . $name, $content);

            return $this->event->files()->create([
                'tag_id' => $this->rider->id, 'path' => 'event-files/1/' . $name, 'original_name' => $name,
                'mime_type' => $mime, 'size' => strlen($content), 'version' => 1,
            ]);
        };

        // Erkannter Inhalt HTML, Endung .txt: herunterladen, nie als Seite
        $response = $this->get($file('liste.txt', 'text/html', '<script>alert(1)</script>')->downloadUrl())->assertOk();
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $response->assertHeader('Content-Security-Policy', EventFileController::SANDBOX)
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // Echter Text: im Browser, aber in der Sandbox
        $response = $this->get($file('notiz.txt', 'text/plain', 'Einlass 18 Uhr')->downloadUrl())->assertOk();
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $response->assertHeader('Content-Security-Policy', EventFileController::SANDBOX);

        // PDF: im Browser mit festem Typ; ohne Sandbox und ohne object-src, sonst zeigt Chrome nichts
        $response = $this->get($file('rider.pdf', 'application/pdf', '%PDF-1.4')->downloadUrl())->assertOk();
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }
}
