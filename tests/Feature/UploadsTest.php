<?php

namespace Tests\Feature;

use App\Filament\Forms\SafeFileUpload;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\FilesRelationManager;
use App\Models\Event;
use App\Models\EventFile;
use App\Models\EventFileTag;
use App\Support\Uploads;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Tests\TestCase;

class UploadsTest extends TestCase
{
    private const TMP = 'livewire-tmp';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Livewires Zwischenablage liegt in Tests auf einer eigenen Disk („tmp-for-tests“)
        Storage::fake(FileUploadConfiguration::disk());
    }

    /** @return list<string> */
    private function tmpFiles(): array
    {
        return Storage::disk(FileUploadConfiguration::disk())->allFiles(self::TMP);
    }

    /** Wie Livewires JavaScript beim Auswählen einer Datei: signierte Adresse, Dateien als files[] */
    private function toTmp(UploadedFile $file): TestResponse
    {
        $url = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), [], false);

        return $this->post($url, ['files' => [$file]], ['Accept' => 'application/json']);
    }

    public function test_zwischenablage_nimmt_nur_erlaubte_dateien_an(): void
    {
        $this->actingAs($this->admin());

        // Programm unter eigenem Namen, wie die Spotify.exe vom 08.10.2026
        $this->toTmp(UploadedFile::fake()->create('Spotify.exe', 100))->assertUnprocessable();

        // Programm, das sich als PDF ausgibt: erkannt am Inhalt, nicht an der Endung
        $path = (string) tempnam(sys_get_temp_dir(), 'vc');
        $this->beforeApplicationDestroyed(fn () => @unlink($path));
        file_put_contents($path, "MZ\x90\x00" . str_repeat("\x00", 256));
        $this->toTmp(new UploadedFile($path, 'rechnung.pdf', null, null, true))->assertUnprocessable();

        // Größer als 25 MB
        $this->toTmp(UploadedFile::fake()->create('plan.pdf', Uploads::MAX_KB + 1, 'application/pdf'))->assertUnprocessable();

        $this->assertSame([], $this->tmpFiles(), 'Abgewiesenes darf nicht in der Zwischenablage landen.');

        // Erlaubt: Datei samt Beschreibung in der Zwischenablage
        $this->toTmp(UploadedFile::fake()->create('rider.pdf', 2048, 'application/pdf'))->assertOk()->assertJsonCount(1, 'paths');
        $this->assertCount(2, $this->tmpFiles());
    }

    public function test_dateiauswahl_bietet_nur_erlaubte_endungen_an(): void
    {
        $manager = $this->filesManager();

        $this->actingAs($this->userWith($this->role('technik', ['events' => 'edit'])));
        $manager()->mountTableAction('create')->assertFormFieldExists('uploads', function (SafeFileUpload $field): bool {
            $types = $field->getAcceptedFileTypes();

            return in_array('.pdf', $types, true) && in_array('.dwg', $types, true)
                && ! in_array('.zip', $types, true) && ! in_array('.exe', $types, true)
                && ($field->getMimeTypeMap()['dwg'] ?? null) === '.dwg'
                && $field->getMaxSize() === Uploads::MAX_KB;
        });

        // Archive bietet die Auswahl nur Admins an, wie in der PHP-Version
        $this->actingAs($this->admin());
        $manager()->mountTableAction('create')
            ->assertFormFieldExists('uploads', fn (SafeFileUpload $field): bool => in_array('.zip', $field->getAcceptedFileTypes(), true));
    }

    public function test_zeichnungen_und_tabellen_gehen_durch_zu_grosse_dateien_nicht(): void
    {
        $manager = $this->filesManager();
        $tag = EventFileTag::create(['name' => 'Plan', 'sort_order' => 10]);
        $this->actingAs($this->userWith($this->role('technik', ['events' => 'edit'])));

        // Endungen, deren Inhalt der Server je nach Version anders erkennt als der Browser
        foreach ([['Hallenplan.dwg', 'image/vnd.dwg'], ['Gästeliste.csv', 'text/csv']] as [$name, $type]) {
            $manager()->callTableAction('create', data: ['tag_id' => $tag->id, 'uploads' => [UploadedFile::fake()->create($name, 500, $type)]])
                ->assertHasNoTableActionErrors();
        }

        $manager()->callTableAction('create', data: ['tag_id' => $tag->id, 'uploads' => [UploadedFile::fake()->create('Rider.pdf', Uploads::MAX_KB + 1, 'application/pdf')]])
            ->assertHasTableActionErrors(['uploads']);

        $this->assertSame(['Gästeliste.csv', 'Hallenplan.dwg'], EventFile::query()->orderBy('original_name')->pluck('original_name')->all());
    }

    private function filesManager(): Closure
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $event = Event::create(['title' => 'Kanonenfieber', 'starts_at' => now()->addWeek()]);

        return fn () => Livewire::test(FilesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class]);
    }
}
