<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\AssignmentRole;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Trade;
use App\Models\User;
use App\Support\Involvement;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Persönlicher Kalender als ICS-Feed (/kalender/persoenlich.ics): die eigenen Dienste, Schlüssel je Konto. */
class PersonalCalendarFeedTest extends TestCase
{
    private User $anna;

    private Trade $trade;

    private Event $concert;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-14 10:00'));

        $this->anna = $this->account(AccountType::VenueEmployee);
        $tom = Contact::create(['name' => 'Tom Meyer', 'positions' => ['VL']]);
        $this->trade = Trade::create(['name' => 'Lichtwerk']);

        $this->concert = Event::create(['title' => 'Rock im Herbst', 'status' => 'bestätigt', 'starts_at' => '2026-10-24 20:00:00']);
        $this->concert->assignments()->create(['role' => AssignmentRole::HouseRepEarly, 'assignee_type' => 'user', 'assignee_id' => $this->anna->id, 'starts_at' => '08:00', 'ends_at' => '16:00']);
        $this->concert->assignments()->create(['role' => AssignmentRole::Unlock, 'assignee_type' => 'user', 'assignee_id' => $this->anna->id]);
        $this->concert->assignments()->create(['role' => AssignmentRole::HouseRepLate, 'assignee_type' => 'contact', 'assignee_id' => $tom->id, 'starts_at' => '16:00', 'ends_at' => '01:00']);
        $this->concert->assignments()->create(['role' => AssignmentRole::SafetyLate, 'assignee_type' => 'trade', 'assignee_id' => $this->trade->id, 'starts_at' => '16:00', 'ends_at' => '01:00']);
        $this->concert->assignments()->create(['role' => AssignmentRole::ProjectLead, 'assignee_type' => 'user', 'assignee_id' => $this->anna->id]);

        $comedy = Event::create(['title' => 'Comedy Abend', 'status' => 'fraglich', 'starts_at' => '2026-11-03 00:00:00']);
        $comedy->assignments()->create(['role' => AssignmentRole::HouseRepLate, 'assignee_type' => 'user', 'assignee_id' => $this->anna->id, 'starts_at' => '17:00', 'ends_at' => '23:30']);
        // Zu lange her: nicht im Feed
        $old = Event::create(['title' => 'Altes Konzert', 'status' => 'bestätigt', 'starts_at' => '2026-05-01 00:00:00']);
        $old->assignments()->create(['role' => AssignmentRole::HouseRepEarly, 'assignee_type' => 'user', 'assignee_id' => $this->anna->id]);
    }

    private function account(AccountType $type, array $permissions = ['kalender' => 'read'], ?int $trade = null): User
    {
        $user = $this->userWith($this->role('r' . $type->value . count(User::all()), $permissions));
        $user->update(['account_type' => $type, 'trade_id' => $trade]);

        return $user->fresh();
    }

    private function feed(string $url): string
    {
        $ics = $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->getContent();
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }

        return str_replace("\r\n ", '', $ics);
    }

    public function test_mitarbeiter_konto_sieht_seine_dienste(): void
    {
        $user = $this->anna;
        $ics = $this->feed('/kalender/persoenlich.ics?token=' . $user->personalFeedToken());

        // 08:00–16:00 Berliner Sommerzeit = 06:00–14:00 UTC
        $this->assertStringContainsString("DTSTART:20261024T060000Z\r\nDTEND:20261024T140000Z\r\nSUMMARY:House Rep. früh · Rock im Herbst\r\n", $ics);
        // Ohne Uhrzeit ganztägig
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261024\r\nDTEND;VALUE=DATE:20261025\r\nSUMMARY:Aufschließen · Rock im Herbst\r\n", $ics);
        // Fragliches Event: vorläufig; nach der Umstellung auf Winterzeit UTC+1
        $this->assertStringContainsString("DTSTART:20261103T160000Z\r\nDTEND:20261103T223000Z\r\nSUMMARY:House Rep. spät · Comedy Abend\r\n", $ics);
        $this->assertStringContainsString('STATUS:TENTATIVE', $ics);
        $this->assertStringContainsString('/events/' . $this->concert->id . '/edit?phase=planung&bereich=personal', $ics);
        // Nur die eigenen, ohne Projektleitung, ohne Altes
        $this->assertSame(3, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringNotContainsString('Altes Konzert', $ics);
    }

    public function test_gewerk_konto_und_dienst_ueber_mitternacht(): void
    {
        $user = $this->account(AccountType::TradeAccount, ['kalender_extern' => 'read', 'events_extern' => 'read'], trade: $this->trade->id);
        $ics = $this->feed('/kalender/persoenlich.ics?token=' . $user->personalFeedToken());

        // 16:00 bis 01:00 am Folgetag
        $this->assertStringContainsString("DTSTART:20261024T140000Z\r\nDTEND:20261024T230000Z\r\nSUMMARY:VfV spät · Rock im Herbst\r\n", $ics);
        $this->assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
        // Externe landen in „Meine Events“
        $this->assertStringContainsString('/extern/events/' . $this->concert->id, $ics);
    }

    public function test_freelancer_mit_eigenem_dienst(): void
    {
        $freelancer = $this->account(AccountType::Freelancer, ['kalender_extern' => 'read', 'events_extern' => 'read']);
        $this->assertFalse(Involvement::involves($freelancer, $this->concert));
        $this->concert->assignments()->where('role', AssignmentRole::HouseRepLate->value)->update(['assignee_type' => 'user', 'assignee_id' => $freelancer->id]);

        $this->assertTrue(Involvement::involves($freelancer, $this->concert));
        $this->assertSame(1, substr_count($this->feed('/kalender/persoenlich.ics?token=' . $freelancer->personalFeedToken()), 'BEGIN:VEVENT'));
        // Kontakte haben keinen Feed: Toms früherer Dienst taucht nirgends mehr auf
        $this->assertStringNotContainsString('House Rep. spät · Rock im Herbst', $this->feed('/kalender/persoenlich.ics?token=' . $this->anna->personalFeedToken()));
    }

    public function test_zugriff(): void
    {
        $user = $this->anna;
        $token = $user->personalFeedToken();

        $this->get('/kalender/persoenlich.ics')->assertForbidden();
        $this->get('/kalender/persoenlich.ics?token=falsch-falsch-falsch-falsch')->assertForbidden();
        // Angemeldet ohne Schlüssel: der eigene; ein falscher Schlüssel öffnet nichts
        $this->actingAs($user);
        $this->assertSame(3, substr_count($this->feed('/kalender/persoenlich.ics'), 'BEGIN:VEVENT'));
        $this->get('/kalender/persoenlich.ics?token=falsch-falsch-falsch-falsch')->assertForbidden();

        // Gesperrt oder ohne Kalender-Recht: nichts
        $user->update(['is_active' => false]);
        $this->get('/kalender/persoenlich.ics?token=' . $token)->assertForbidden();
        $noCalendar = $this->account(AccountType::VenueEmployee, ['events' => 'read']);
        $this->get('/kalender/persoenlich.ics?token=' . $noCalendar->personalFeedToken())->assertForbidden();
    }
}
