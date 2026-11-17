<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\GuestsRelationManager;
use App\Models\AuditLog;
use App\Models\Event;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Lehnt der Mailserver ab (etwa falsches Passwort in der .env), melden die
 * Versand-Dialoge „Versand fehlgeschlagen“ – statt „Fehler beim Laden der
 * Seite“ – und speichern nichts (so am 17.11.2026 auf ce.vcontrol.eu passiert).
 */
class MailFailureTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->travelTo(Carbon::parse('2026-11-17 10:00'));
        $this->event = Event::create(['title' => 'Gala', 'status' => 'bestätigt', 'starts_at' => '2026-12-05 20:00:00']);
        $this->actingAs($this->userWith($this->role('eventmanager', ['events' => 'edit'])));
        Mail::shouldReceive('to')->andThrow(new TransportException('535 5.7.8 Error: authentication failed'));
    }

    public function test_daysheet_meldet_versandfehler_und_speichert_nichts(): void
    {
        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->callAction('sendDaysheet', data: ['to' => ['buero@example.org'], 'bcc' => [], 'subject' => 'Daysheet', 'body' => '{link}'])
            ->assertNotified('Versand fehlgeschlagen');

        $this->assertSame(0, $this->event->daysheets()->count());
    }

    public function test_gaesteliste_meldet_versandfehler_und_speichert_nichts(): void
    {
        $this->event->guests()->create(['first_name' => 'Erika', 'last_name' => 'Muster', 'free_tickets' => 2]);

        Livewire::test(GuestsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class])
            ->callTableAction('sendGuestList', data: ['to' => ['einlass@example.org'], 'bcc' => [], 'subject' => 'Gästeliste', 'body' => 'Hallo'])
            ->assertNotified('Versand fehlgeschlagen');

        $this->assertFalse(AuditLog::query()->where('new_values', 'like', '%guest_list_sent_to%')->exists());
    }
}
