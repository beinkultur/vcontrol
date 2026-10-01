<?php

namespace Tests\Feature;

use App\Filament\Resources\Damages\Pages\ManageDamages;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\DamagesRelationManager;
use App\Mail\DamageReported;
use App\Models\Damage;
use App\Models\Event;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DamagesTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Storage::fake(Damage::DISK);
        Mail::fake();
        $this->event = Event::create(['title' => 'Konzert', 'starts_at' => now()]);
    }

    private function manager(string $page = EditEvent::class)
    {
        return Livewire::test(DamagesRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => $page]);
    }

    public function test_schaden_mit_foto_melden_geht_an_den_hausmeister(): void
    {
        $hausmeister = $this->userWith($this->role('hausmeister', ['events' => 'read']));
        $this->userWith($this->role('technik', ['events' => 'read']));
        $this->actingAs($admin = $this->admin());

        $this->manager()->callTableAction('create', data: [
            'recorded_at' => '2026-10-09 23:15',
            'description' => 'Geländer an Tribüne B verbogen',
            'photos' => [UploadedFile::fake()->image('gelaender.jpg', 800, 600)],
        ])->assertHasNoTableActionErrors();

        $damage = $this->event->damages()->firstOrFail();
        $this->assertSame('Geländer an Tribüne B verbogen', $damage->description);
        $this->assertFalse($damage->is_fixed);
        $this->assertSame($admin->getFilamentName(), $damage->recorderName());
        $this->assertCount(1, $damage->photos);
        Storage::disk(Damage::DISK)->assertExists($damage->photos[0]);
        $this->assertSame('gelaender.jpg', $damage->photo_names[$damage->photos[0]]);

        Mail::assertSent(DamageReported::class, fn (DamageReported $mail): bool => $mail->hasTo($hausmeister->email)
            && str_contains($mail->link, 'phase=durchfuehrung&bereich=schaeden'));
        Mail::assertSentCount(1);

        // Foto mit Rechteprüfung
        $this->get($damage->photoUrls()[0])->assertOk()->assertHeader('content-disposition');
        $this->actingAs($this->userWith($this->role('gast')));
        $this->get($damage->photoUrls()[0])->assertForbidden();
    }

    public function test_feste_empfaenger_statt_hausmeister(): void
    {
        config(['venuecontrol.damage_notify' => 'technik@example.org, haus@example.org']);
        $this->userWith($this->role('hausmeister', ['events' => 'read']));
        $this->actingAs($this->admin());

        $this->manager()->callTableAction('create', data: [
            'recorded_at' => '2026-10-09 23:15',
            'description' => 'Fliese im Foyer gesprungen',
        ])->assertHasNoTableActionErrors();

        Mail::assertSentCount(2);
        Mail::assertSent(DamageReported::class, fn (DamageReported $mail): bool => $mail->hasTo('haus@example.org'));
    }

    public function test_beschreibung_ist_pflicht(): void
    {
        $this->actingAs($this->admin());

        $this->manager()->callTableAction('create', data: ['description' => ''])
            ->assertHasTableActionErrors(['description' => 'required']);
        $this->assertSame(0, Damage::count());
        Mail::assertNothingSent();
    }

    public function test_behoben_setzen_event_operationen_oder_buchhaltung(): void
    {
        $damage = $this->event->damages()->create(['recorded_at' => now(), 'description' => 'Tür klemmt']);

        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        $this->manager(ViewEvent::class)
            ->assertCanSeeTableRecords([$damage])
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $damage)
            ->assertTableActionHidden('fix', $damage)
            ->assertTableActionVisible('view', $damage);

        $this->actingAs($this->userWith($this->role('buchhaltung', ['events' => 'read', 'buchhaltung' => 'edit'])));
        $this->manager(ViewEvent::class)->callTableAction('fix', $damage);
        $this->assertTrue($damage->fresh()->is_fixed);

        $this->actingAs($this->userWith($this->role('einlass', ['events' => 'read', 'events_operations' => 'edit'])));
        $this->manager()->callTableAction('fix', $damage);
        $this->assertFalse($damage->fresh()->is_fixed);
        $this->manager()->assertTableActionDoesNotExist('delete'); // Schäden werden nicht gelöscht
    }

    public function test_hausmeister_meldet_in_der_ansicht_und_kommt_ueber_den_link_hin(): void
    {
        // Rechte der Rolle „hausmeister“ aus der PHP-Version: Events lesen, Event-Operationen bearbeiten
        $this->actingAs($this->userWith($this->role('hausmeister', ['events' => 'read', 'events_operations' => 'edit', 'protokolle' => 'read'])));

        $this->manager(ViewEvent::class)
            ->assertTableActionVisible('create')
            ->callTableAction('create', data: ['recorded_at' => '2026-10-09 23:15', 'description' => 'Handlauf locker'])
            ->assertHasNoTableActionErrors();
        $damage = $this->event->damages()->firstOrFail();
        $this->manager(ViewEvent::class)->assertTableActionVisible('edit', $damage)->callTableAction('fix', $damage);
        $this->assertTrue($damage->fresh()->is_fixed);

        // Link aus der Mail zeigt auf die Bearbeiten-Seite: wer nur lesen darf, landet in der Ansicht
        $this->get("/events/{$this->event->id}/edit?phase=durchfuehrung&bereich=schaeden")
            ->assertRedirect("/events/{$this->event->id}?phase=durchfuehrung&bereich=schaeden");
        $this->get("/events/{$this->event->id}?phase=durchfuehrung&bereich=schaeden")->assertOk();
    }

    public function test_allgemeiner_schaden_in_der_uebersicht(): void
    {
        $this->userWith($this->role('hausmeister', ['events' => 'read']));
        $this->actingAs($this->userWith($this->role('haustechnik', ['protokolle' => 'read', 'events_operations' => 'edit'])));

        Livewire::test(ManageDamages::class)->callAction('create', data: [
            'event_id' => null,
            'recorded_at' => '2026-10-02 08:00',
            'description' => 'Rolltor Anlieferung schließt nicht',
        ])->assertHasNoActionErrors();

        $damage = Damage::query()->firstOrFail();
        $this->assertNull($damage->event_id);
        Mail::assertSent(DamageReported::class, fn (DamageReported $mail): bool => str_ends_with($mail->link, '/schaeden'));

        $other = $this->event->damages()->create(['recorded_at' => now(), 'description' => 'Spiegel Backstage']);
        Livewire::test(ManageDamages::class)
            ->assertCanSeeTableRecords([$damage, $other])
            ->filterTable('general', true)
            ->assertCanSeeTableRecords([$damage])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_uebersicht_braucht_protokolle(): void
    {
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        $this->get('/schaeden')->assertForbidden();

        $this->actingAs($this->userWith($this->role('protokoll', ['protokolle' => 'read'])));
        $this->get('/schaeden')->assertOk();
    }
}
