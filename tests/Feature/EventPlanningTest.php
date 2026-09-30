<?php

namespace Tests\Feature;

use App\Enums\AssignmentRole;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Employee;
use App\Models\Event;
use App\Models\Room;
use App\Models\Trade;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class EventPlanningTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($this->admin());
        $this->event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
    }

    public function test_raeume_als_backstage_und_buero_zuordnen(): void
    {
        $lounge = Room::create(['name' => 'Lounge', 'sort_order' => 10]);
        $buero = Room::create(['name' => 'Produktionsbüro', 'sort_order' => 20]);

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->fillForm(['backstageRooms' => [$lounge->id], 'officeRooms' => [$buero->id, $lounge->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$lounge->id], $this->event->backstageRooms()->pluck('rooms.id')->all());
        $this->assertEqualsCanonicalizing([$buero->id, $lounge->id], $this->event->officeRooms()->pluck('rooms.id')->all());

        // Backstage leeren lässt die Büros stehen
        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->fillForm(['backstageRooms' => []])
            ->call('save');

        $this->assertSame(0, $this->event->backstageRooms()->count());
        $this->assertSame(2, $this->event->officeRooms()->count());
    }

    public function test_rollen_vergeben_aendern_und_leeren(): void
    {
        $anna = Employee::create(['first_name' => 'Anna', 'last_name' => 'Leitung']);
        $sidi = Trade::create(['name' => 'Sicherheitsdienst']);

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->fillForm(['role_pl' => 'employee:' . $anna->id, 'role_safety_1' => 'trade:' . $sidi->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $roles = $this->event->assignments()->get()->keyBy(fn ($a) => $a->role->value);
        $this->assertSame('Anna Leitung', $roles['pl']->assigneeName());
        $this->assertSame('Sicherheitsdienst', $roles['safety_1']->assigneeName());

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->assertSchemaStateSet(['role_pl' => 'employee:' . $anna->id])
            ->fillForm(['role_pl' => null])
            ->call('save');

        $this->assertNull($this->event->assignments()->where('role', AssignmentRole::ProjectLead->value)->first());
        $this->assertNotNull($this->event->assignments()->where('role', AssignmentRole::SafetyEarly->value)->first());
    }

    public function test_uhrzeiten_der_rollen(): void
    {
        $sidi = Trade::create(['name' => 'Sicherheitsdienst']);
        $this->event->assignments()->create([
            'role' => AssignmentRole::SafetyEarly, 'assignee_type' => 'trade', 'assignee_id' => $sidi->id,
            'starts_at' => '03:00:00', 'ends_at' => '17:30:00',
        ]);

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->assertSchemaStateSet(['role_safety_1_starts_at' => '03:00', 'role_safety_1_ends_at' => '17:30'])
            ->fillForm(['role_safety_1_starts_at' => '08:00', 'role_safety_1_ends_at' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $assignment = $this->event->assignments()->firstOrFail();
        $this->assertSame('08:00:00', substr((string) $assignment->starts_at, 0, 8));
        $this->assertNull($assignment->ends_at);

        // Ohne Person keine Zeiten: Die Rolle verschwindet samt Uhrzeit.
        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->fillForm(['role_safety_1' => null])
            ->call('save');
        $this->assertSame(0, $this->event->assignments()->count());
    }

    public function test_unbekannter_typ_wird_nicht_gespeichert(): void
    {
        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])
            ->fillForm(['role_pl' => 'promoter:1'])
            ->call('save');

        $this->assertSame(0, $this->event->assignments()->count());
    }
}
