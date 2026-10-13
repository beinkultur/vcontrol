<?php

namespace Tests\Feature;

use App\Enums\AssignmentRole;
use App\Enums\Responsible;
use App\Enums\ServiceCode;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Room;
use App\Models\Trade;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class EventListsTest extends TestCase
{
    private Event $event;

    private Room $lounge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = Event::create(['title' => 'Konzert', 'status' => 'bestätigt', 'starts_at' => now()->addWeek()]);
        $this->lounge = Room::create(['name' => 'Lounge']);
        $this->event->rooms()->attach($this->lounge->id, ['usage_type' => 'backstage']);
    }

    public function test_rollen_an_kontakt_gewerk_und_benutzer(): void
    {
        $contact = Contact::create(['name' => 'Anna Leitung']);
        $trade = Trade::create(['name' => 'Sicherheitsdienst GmbH', 'short_name' => 'SiDi']);
        $this->event->assignments()->create(['role' => AssignmentRole::ProjectLead, 'assignee_type' => 'contact', 'assignee_id' => $contact->id]);
        $this->event->assignments()->create(['role' => AssignmentRole::SafetyEarly, 'assignee_type' => 'trade', 'assignee_id' => $trade->id]);

        $names = $this->event->assignments()->get()->mapWithKeys(fn ($a) => [$a->role->value => $a->assigneeName()]);
        $this->assertSame('Anna Leitung', $names['pl']);
        $this->assertSame('SiDi', $names['safety_1']);
    }

    public function test_belegter_raum_ist_nicht_loeschbar(): void
    {
        $admin = $this->admin();
        $frei = Room::create(['name' => 'Abstellraum']);

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $this->lounge));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $frei));

        // Auch an der Policy vorbei hält die Datenbank den Raum fest
        $this->expectException(QueryException::class);
        $this->lounge->delete();
    }

    public function test_detailansicht_zeigt_rollen_raeume_und_leistungen(): void
    {
        $contact = Contact::create(['name' => 'Anna Leitung']);
        $this->event->assignments()->create(['role' => AssignmentRole::ProjectLead, 'assignee_type' => 'contact', 'assignee_id' => $contact->id]);
        $this->event->services()->create(['service' => ServiceCode::Security, 'responsible' => Responsible::Promoter]);

        $this->actingAs($this->admin());
        $this->get('/events/' . $this->event->id)
            ->assertOk()
            ->assertSee('Anna Leitung')
            ->assertSee('Lounge');

        Filament::setCurrentPanel(Filament::getPanel('app'));
        Livewire::test(ViewEvent::class, ['record' => $this->event->getRouteKey()])
            ->assertSchemaStateSet([
                'role_pl' => 'contact:' . $contact->id,
                'service_security_responsible' => Responsible::Promoter,
                'room_' . $this->lounge->id => 'backstage',
            ]);
    }
}
