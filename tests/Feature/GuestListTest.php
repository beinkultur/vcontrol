<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\GuestsRelationManager;
use App\Mail\GuestListMail;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Setting;
use App\Support\GuestLists;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class GuestListTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->event = Event::create(['title' => 'Gala', 'starts_at' => now()->addWeek()]);
    }

    public function test_gaeste_anlegen_aendern_loeschen(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $manager = fn () => Livewire::test(GuestsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class]);

        $manager()->callTableAction('create', data: ['first_name' => 'Erika', 'last_name' => 'Muster', 'free_tickets' => 2])
            ->assertHasNoTableActionErrors();
        $manager()->callTableAction('create', data: ['first_name' => 'Max', 'last_name' => 'Beispiel', 'free_tickets' => 3])
            ->assertHasNoTableActionErrors();

        $erika = $this->event->guests()->where('last_name', 'Muster')->firstOrFail();
        $this->assertSame($admin->id, $erika->created_by);
        $this->assertSame(5, (int) $this->event->guests()->sum('free_tickets'));

        $manager()->callTableAction('edit', $erika, data: ['free_tickets' => 4])->assertHasNoTableActionErrors();
        $this->assertSame(4, $erika->fresh()->free_tickets);

        $manager()->callTableAction('delete', $erika);
        $this->assertSame(1, $this->event->guests()->count());
    }

    public function test_name_und_vorname_sind_pflicht(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(GuestsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class])
            ->callTableAction('create', data: ['first_name' => '', 'last_name' => '', 'free_tickets' => 1])
            ->assertHasTableActionErrors(['first_name' => 'required', 'last_name' => 'required']);
    }

    public function test_leserolle_sieht_die_liste_aber_aendert_nichts(): void
    {
        $this->event->guests()->create(['first_name' => 'Erika', 'last_name' => 'Muster', 'free_tickets' => 2]);
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));

        Livewire::test(GuestsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => ViewEvent::class])
            ->assertCanSeeTableRecords($this->event->guests)
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $this->event->guests->first());
    }

    public function test_druckansicht_sortiert_mit_summe(): void
    {
        $this->event->guests()->createMany([
            ['first_name' => 'Max', 'last_name' => 'Zander', 'free_tickets' => 2],
            ['first_name' => 'Erika', 'last_name' => 'Albers', 'free_tickets' => 3],
        ]);
        $this->actingAs($this->userWith($this->role('einlass', ['events' => 'read'])));

        Livewire::test(GuestsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => ViewEvent::class])
            ->assertTableActionVisible('print');

        $this->get("/events/{$this->event->id}/gaesteliste")
            ->assertOk()
            ->assertSeeInOrder(['Gala', 'Albers', 'Erika', 'Zander', 'Max', 'Gesamt Tickets', '5']);
    }

    public function test_druckansicht_nur_mit_recht_und_anmeldung(): void
    {
        $this->get("/events/{$this->event->id}/gaesteliste")->assertRedirect();

        $this->actingAs($this->userWith($this->role('buchhaltung', ['buchhaltung' => 'read'])));
        $this->get("/events/{$this->event->id}/gaesteliste")->assertForbidden();
    }

    public function test_pdf_immer_din_a4(): void
    {
        $this->event->guests()->createMany([
            ['first_name' => 'Max', 'last_name' => 'Zander', 'free_tickets' => 2],
            ['first_name' => 'Erika', 'last_name' => 'Albers', 'free_tickets' => 3],
        ]);
        $this->actingAs($this->userWith($this->role('einlass', ['events' => 'read'])));

        $response = $this->get(route('events.guest-list-pdf', $this->event))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        // DIN A4 hoch in Punkten: 595,28 × 841,89
        $this->assertMatchesRegularExpression('#/MediaBox \[0(\.0+)? 0(\.0+)? 595\.28\d* 841\.89\d*\]#', $pdf);
        $this->assertStringContainsString("filename*=utf-8''G%C3%A4steliste%20Gala%20", (string) $response->headers->get('Content-Disposition'));

        // Die Druckansicht gibt A4 vor und bietet das PDF an
        $this->get(route('events.guest-list-print', $this->event))->assertOk()
            ->assertSee('size: A4 portrait', false)
            ->assertSee(route('events.guest-list-pdf', $this->event), false);

        // Ohne Recht auf das Event: nichts
        $this->actingAs($this->userWith($this->role('buchhaltung', ['buchhaltung' => 'read'])));
        $this->get(route('events.guest-list-pdf', $this->event))->assertForbidden();
    }

    public function test_per_mail_mit_pdf_im_anhang(): void
    {
        Mail::fake();
        Setting::put(Setting::VENUE_NAME, 'Be-In Arena');
        $manager = fn (string $page = EditEvent::class) => Livewire::test(GuestsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => $page]);
        $this->actingAs($editor = $this->userWith($this->role('eventmanager', ['events' => 'edit'])));

        // Ohne Gäste gibt es nichts zu senden
        $manager()->assertTableActionVisible('sendGuestList')->assertTableActionDisabled('sendGuestList');

        $this->event->guests()->createMany([
            ['first_name' => 'Max', 'last_name' => 'Zander', 'free_tickets' => 2],
            ['first_name' => 'Erika', 'last_name' => 'Albers', 'free_tickets' => 3],
        ]);
        $defaults = GuestLists::defaults($this->event, $editor);
        $this->assertSame([mb_strtolower($editor->email)], $defaults['bcc']);
        $this->assertStringStartsWith('Gästeliste Gala – ', $defaults['subject']);
        $this->assertStringContainsString('2 Gäste mit 5 Freikarten', $defaults['body']);

        $manager()->callTableAction('sendGuestList', data: ['to' => [], 'subject' => 'X', 'body' => 'Y'])->assertHasTableActionErrors(['to']);
        $manager()->callTableAction('sendGuestList', data: [
            'to' => ['Einlass@Example.org'],
            'bcc' => [],
            'subject' => 'Gästeliste {event}',
            'body' => "Hallo,\n\n{gaeste} Gäste, {freikarten} Freikarten.\n\nGrüße\n{venue}",
        ])->assertHasNoTableActionErrors()->assertNotified('Gästeliste verschickt an Einlass@Example.org');

        Mail::assertSent(GuestListMail::class, fn (GuestListMail $mail): bool => $mail->hasTo('einlass@example.org')
            && $mail->subjectLine === 'Gästeliste Gala'
            && str_contains($mail->text, '2 Gäste, 5 Freikarten.')
            && str_contains($mail->text, 'Be-In Arena')
            && str_starts_with($mail->pdf, '%PDF')
            && $mail->fileName === GuestLists::fileName($this->event));
        Mail::assertSentCount(1);
        $log = AuditLog::query()->where('subject', 'events')->where('new_values', 'like', '%guest_list_sent_to%')->sole();
        $this->assertSame($this->event->id, $log->event_id);

        // Leser verschicken nicht
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        $manager(ViewEvent::class)->assertTableActionHidden('sendGuestList')->assertTableActionVisible('pdf');
    }
}
