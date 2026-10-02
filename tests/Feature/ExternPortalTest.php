<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\AssignmentRole;
use App\Enums\ServiceCode;
use App\Filament\Resources\ExternEvents\Pages\ListExternEvents;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventFile;
use App\Models\EventFileTag;
use App\Models\ExternEvent;
use App\Models\Trade;
use App\Models\User;
use App\Support\Involvement;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ExternPortalTest extends TestCase
{
    private User $freelancer;

    private Event $mine;

    private Event $other;

    private EventFile $rider;

    private EventFile $contract;

    private EventFile $otherFile;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Storage::fake(EventFile::DISK);

        $this->freelancer = $this->userWith($this->role('extern', ['events_extern' => 'read']));
        $this->freelancer->update(['account_type' => AccountType::Freelancer]);

        $this->mine = Event::create(['title' => 'Konzert Nord', 'status' => 'bestätigt', 'starts_at' => now()->addDays(3),
            'booking_notes' => 'Intern: Gage nachverhandeln', 'wlan_password' => 'geheimwlan', 'onsite_contact' => 'Max Tourleiter']);
        $this->other = Event::create(['title' => 'Geheime Messe', 'status' => 'bestätigt', 'starts_at' => now()->addDays(4)]);

        // Beteiligt als Konto im Personal
        $this->mine->assignments()->create(['role' => AssignmentRole::HouseRepEarly, 'assignee_type' => 'user',
            'assignee_id' => $this->freelancer->id, 'starts_at' => '14:00:00', 'ends_at' => '23:00:00']);
        $this->mine->schedule()->create(['admission' => '18:30:00', 'start_time' => '20:00:00']);
        $this->mine->finance()->create(['rent' => 12345, 'contract_status' => 'Rahmenvertrag unterschrieben']);
        $this->mine->notes()->create(['subject' => 'Anfahrt', 'body' => 'Ladehof über Tor 3']);
        $this->mine->notes()->create(['subject' => 'Team-Absprache', 'body' => 'Nur fürs Team', 'hidden_from_externals' => true]);

        $tag = EventFileTag::create(['name' => 'Technical Rider', 'sort_order' => 1]);
        $file = function (Event $event, string $name, bool $hidden = false) use ($tag): EventFile {
            $path = 'event-files/' . $event->id . '/' . $name;
            Storage::disk(EventFile::DISK)->put($path, '%PDF-1.4');

            return $event->files()->create(['tag_id' => $tag->id, 'path' => $path, 'original_name' => $name,
                'mime_type' => 'application/pdf', 'size' => 8, 'version' => 1, 'hidden_from_externals' => $hidden]);
        };
        $this->rider = $file($this->mine, 'Rider.pdf');
        $this->contract = $file($this->mine, 'Mietvertrag.pdf', hidden: true);
        $this->otherFile = $file($this->other, 'Messeplan.pdf');
    }

    public function test_extern_sieht_nur_beteiligte_events(): void
    {
        $this->actingAs($this->freelancer);

        $this->get('/')->assertRedirect('/extern/events');
        Livewire::test(ListExternEvents::class)
            ->assertCanSeeTableRecords([ExternEvent::query()->findOrFail($this->mine->id)])
            ->assertCanNotSeeTableRecords([ExternEvent::query()->findOrFail($this->other->id)]);

        $this->get('/extern/events/' . $this->mine->id)->assertOk();
        $this->get('/extern/events/' . $this->other->id)->assertForbidden();

        // Die internen Seiten und Ausgaben desselben Events bleiben zu
        foreach (['/events/' . $this->mine->id, '/events/' . $this->mine->id . '/edit', '/events/' . $this->mine->id . '/gaesteliste',
            '/events/' . $this->mine->id . '/buehnenplan/druck', '/events/' . $this->mine->id . '/daysheet'] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_ansicht_ohne_sensibles_und_ohne_verborgenes(): void
    {
        $this->actingAs($this->freelancer);

        $this->get('/extern/events/' . $this->mine->id)
            ->assertOk()
            ->assertSee('Konzert Nord')
            ->assertSee('18:30 Uhr')
            ->assertSee('House Rep. früh')
            ->assertSee('14:00–23:00')
            ->assertSee('Max Tourleiter')
            ->assertSee('Ladehof über Tor 3')
            ->assertSee('Rider.pdf')
            ->assertDontSee('Nur fürs Team')
            ->assertDontSee('Mietvertrag.pdf')
            ->assertDontSee('12345')
            ->assertDontSee('12.345')
            ->assertDontSee('Rahmenvertrag unterschrieben')
            ->assertDontSee('Gage nachverhandeln')
            ->assertDontSee('geheimwlan');
    }

    public function test_dateien_nur_sichtbare_von_beteiligten_events(): void
    {
        $this->actingAs($this->freelancer);

        $this->get($this->rider->downloadUrl())->assertOk();
        $this->get($this->contract->downloadUrl())->assertForbidden();
        $this->get($this->otherFile->downloadUrl())->assertForbidden();
    }

    public function test_beteiligung_ueber_gewerk_und_mitarbeiter(): void
    {
        $trade = Trade::create(['name' => 'Licht & Ton GmbH', 'email' => 'licht@example.org']);
        $gewerk = $this->userWith($this->role('extern_gewerk', ['events_extern' => 'read']));
        $gewerk->update(['account_type' => AccountType::TradeAccount, 'trade_id' => $trade->id]);
        $this->other->services()->create(['service' => ServiceCode::Vt, 'trade_id' => $trade->id]);

        $this->assertTrue(Involvement::involves($gewerk->fresh(), $this->other));
        $this->assertFalse(Involvement::involves($gewerk->fresh(), $this->mine));

        $employee = Employee::create(['first_name' => 'Kai', 'last_name' => 'Hausmeister']);
        $mitarbeiter = $this->userWith($this->role('extern_ma', ['events_extern' => 'read']));
        $mitarbeiter->update(['account_type' => AccountType::VenueEmployee, 'employee_id' => $employee->id]);
        $this->mine->assignments()->create(['role' => AssignmentRole::Lock, 'assignee_type' => 'employee', 'assignee_id' => $employee->id]);

        $this->assertTrue(Involvement::involves($mitarbeiter->fresh(), $this->mine));
        $this->assertFalse(Involvement::involves($mitarbeiter->fresh(), $this->other));

        // Ein Gewerk-Konto zählt nicht über einen Mitarbeiter, ein Mitarbeiter-Konto nicht über ein Gewerk
        $gewerk->update(['employee_id' => $employee->id]);
        $this->assertFalse(Involvement::involves($gewerk->fresh(), $this->mine));
    }

    public function test_intern_sieht_die_ansicht_extern_aber_nicht_die_liste(): void
    {
        $this->actingAs($this->userWith($this->role('eventmanager', ['events' => 'edit'])));

        $this->get('/extern/events/' . $this->other->id)->assertOk()->assertSee('Messeplan.pdf');
        $this->get('/extern/events')->assertForbidden();
    }
}
