<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\AdvancingRelationManager;
use App\Filament\Support\AdvancingFields;
use App\Mail\DaysheetMail;
use App\Models\AdvancingTemplate;
use App\Models\Event;
use App\Models\EventAdvancing;
use App\Models\Setting;
use App\Models\User;
use App\Support\AdvancingCatalog;
use App\Support\Advancings;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AdvancingWorkspaceTest extends TestCase
{
    private Event $event;

    private AdvancingTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-17 10:00'));
        Setting::put(Setting::VENUE_NAME, 'LKH Arena');

        $this->template = AdvancingCatalog::import(json_decode((string) file_get_contents(base_path('database/advancing/lkh-arena-kultur.json')), true));
        $this->event = Event::create(['title' => 'Comedy Abend', 'status' => 'bestätigt', 'starts_at' => '2026-12-05 20:00:00', 'onsite_email' => 'tour@example.org']);
        $this->event->schedule()->create(['get_in' => '11:30:00', 'admission' => '17:30:00']);
    }

    private function manager(string $page = EditEvent::class): Testable
    {
        return Livewire::test(AdvancingRelationManager::class, ['ownerRecord' => $this->event->fresh(), 'pageClass' => $page]);
    }

    private function planer(): User
    {
        $planer = $this->userWith($this->role('eventmanager', ['events' => 'edit']));
        $this->actingAs($planer);

        return $planer;
    }

    private function sent(): EventAdvancing
    {
        $advancing = Advancings::start($this->event, $this->template);
        Advancings::send($advancing, ['tour@example.org'], [], 'Advancing', '{link}', null);

        return $advancing->fresh();
    }

    public function test_reiter_nur_mit_modul(): void
    {
        $this->actingAs($this->admin());
        // „Advancing“ steht auch in der Beschreibung der Phase Planung – geprüft wird der Reiter
        $this->get(EventResource::getUrl('edit', ['record' => $this->event]))->assertOk()->assertSee('data-tab-key="advancing"', false);
        $this->assertTrue(AdvancingRelationManager::canViewForRecord($this->event, EditEvent::class));

        Setting::put(Setting::MODULES, 'saalplan');
        app()->forgetScopedInstances();
        $this->get(EventResource::getUrl('edit', ['record' => $this->event]))->assertOk()->assertDontSee('data-tab-key="advancing"', false);
        $this->assertFalse(AdvancingRelationManager::canViewForRecord($this->event, EditEvent::class));
    }

    public function test_planer_startet_belegt_vor_und_versendet(): void
    {
        $this->planer();

        $this->manager()
            ->assertTableActionVisible('startAdvancing')
            ->callTableAction('startAdvancing', data: ['template' => $this->template->id])
            ->assertHasNoTableActionErrors();

        $advancing = $this->event->advancing()->firstOrFail();
        $this->assertSame('11:30 Uhr', $advancing->prefill['get_in']);
        $this->manager()->assertTableActionHidden('startAdvancing')->assertCanSeeTableRecords([$advancing]);

        $this->manager()
            ->callTableAction('send', $advancing, data: ['to' => ['tour@example.org'], 'bcc' => [], 'subject' => 'Advancing {event}', 'body' => 'Hallo, {link}'])
            ->assertHasNoTableActionErrors();

        Mail::assertSent(DaysheetMail::class, fn (DaysheetMail $mail): bool => $mail->hasTo('tour@example.org')
            && $mail->subjectLine === 'Advancing Comedy Abend'
            && $mail->buttonLabel === 'Fragenkatalog öffnen');
        $this->assertTrue($advancing->fresh()->isLinkValid());
    }

    public function test_versand_ohne_datum_meldet_den_grund(): void
    {
        $this->planer();
        $advancing = Advancings::start($this->event, $this->template);
        $this->event->update(['starts_at' => null]);

        $this->manager()
            ->callTableAction('send', $advancing, data: ['to' => ['tour@example.org'], 'bcc' => [], 'subject' => 'A', 'body' => '{link}'])
            ->assertNotified('Das Event hat noch kein Datum – ohne Datum gibt es keinen Link.');
        Mail::assertNothingSent();
    }

    public function test_planer_aendert_vorbelegung_und_sieht_aenderungen_des_veranstalters(): void
    {
        $this->planer();
        $advancing = Advancings::start($this->event, $this->template);
        $advancing->update(['answers' => ['einlass' => '18:00 Uhr']]);

        // Vorbelegung im Formular, die Antwort des Veranstalters als Hinweis am Feld
        $this->manager()
            ->mountTableAction('edit', $advancing)
            ->assertTableActionDataSet(['prefill.einlass' => '17:30 Uhr', 'prefill.get_in' => '11:30 Uhr']);
        $this->assertSame('Veranstalter: 18:00 Uhr', AdvancingFields::changeHint($advancing->fresh(), 'einlass'));
        $this->assertNull(AdvancingFields::changeHint($advancing->fresh(), 'get_in'));

        $this->manager()
            ->callTableAction('edit', $advancing, data: ['prefill' => ['busse' => '3', 'aufbau_bestuhlung' => '16:00 Uhr ok?', 'pkw' => '']])
            ->assertHasNoTableActionErrors();

        $prefill = $advancing->fresh()->prefill;
        $this->assertSame('3', $prefill['busse']);
        $this->assertSame('16:00 Uhr ok?', $prefill['aufbau_bestuhlung']);
        // Unverändertes bleibt, Leeres fällt weg
        $this->assertSame('11:30 Uhr', $prefill['get_in']);
        $this->assertArrayNotHasKey('pkw', $prefill);
        // Die Antwort des Veranstalters bleibt unberührt
        $this->assertSame(['einlass' => '18:00 Uhr'], $advancing->fresh()->answers);
    }

    public function test_leserolle_sieht_aber_aendert_nicht(): void
    {
        $advancing = $this->sent();
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));

        $this->assertTrue(AdvancingRelationManager::canViewForRecord($this->event, ViewEvent::class));
        $this->manager(ViewEvent::class)
            ->assertCanSeeTableRecords([$advancing])
            ->assertTableActionVisible('view', $advancing)
            ->assertTableActionHidden('edit', $advancing)
            ->assertTableActionHidden('send', $advancing)
            ->assertTableActionHidden('copyLink', $advancing)
            ->assertTableActionHidden('open', $advancing)
            ->assertTableActionHidden('revoke', $advancing)
            ->assertTableActionHidden('close', $advancing)
            ->assertDontSee($advancing->link());

        $this->actingAs($this->userWith($this->role('ohne', ['kalender' => 'read'])));
        $this->assertFalse(AdvancingRelationManager::canViewForRecord($this->event, ViewEvent::class));
    }

    public function test_link_kopieren_nur_mit_gueltigem_link(): void
    {
        $this->planer();
        $advancing = Advancings::start($this->event, $this->template);
        $this->manager()->assertTableActionHidden('copyLink', $advancing)->assertTableActionHidden('open', $advancing);

        Advancings::send($advancing, ['tour@example.org'], [], 'A', '{link}', null);
        $advancing->refresh();
        $this->manager()
            ->assertTableActionVisible('open', $advancing)
            ->mountTableAction('copyLink', $advancing)
            ->assertSee($advancing->link());
    }

    public function test_sperren_entsperren_und_abschliessen(): void
    {
        $planer = $this->planer();
        $advancing = $this->sent();

        $this->manager()->callTableAction('revoke', $advancing);
        $this->assertSame('gesperrt', $advancing->fresh()->statusLabel());
        $this->manager()->assertTableActionHidden('revoke', $advancing)->callTableAction('unrevoke', $advancing);
        $this->assertTrue($advancing->fresh()->isLinkValid());

        $this->manager()->callTableAction('close', $advancing);
        $advancing->refresh();
        $this->assertSame('abgeschlossen', $advancing->statusLabel());
        $this->assertSame($planer->getFilamentName(), $advancing->closed_by_name);
        $this->manager()->assertTableActionHidden('send', $advancing)->assertTableActionHidden('close', $advancing);
    }
}
