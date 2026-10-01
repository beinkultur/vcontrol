<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\ShowChecklistsRelationManager;
use App\Models\Event;
use App\Models\EventShowChecklist;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class ShowChecklistsTest extends TestCase
{
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=';

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->event = Event::create(['title' => 'Konzert', 'starts_at' => now(), 'onsite_contact' => 'Kim Tour']);
    }

    private function manager(string $page = EditEvent::class)
    {
        return Livewire::test(ShowChecklistsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => $page]);
    }

    public function test_checkliste_mit_pruefpunkten_und_unterschriften(): void
    {
        $this->actingAs($admin = $this->admin());

        $this->manager()
            ->mountTableAction('create')
            ->assertTableActionDataSet(['house_rep' => $admin->getFilamentName(), 'promoter_rep' => 'Kim Tour'])
            ->setTableActionData([
                'checked_at' => '2026-10-09 23:40',
                'checks' => [
                    'emergency_case' => ['value' => 'yes'],
                    'barriers' => ['value' => 'no', 'note' => '2 Barriers fehlen'],
                    'screws_long' => ['value' => 'yes', 'note' => 'nachgezählt'],
                ],
                'remarks' => 'Rest morgen früh',
                'house_rep_signature' => self::SIGNATURE,
                'promoter_rep_signature' => self::SIGNATURE,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $list = $this->event->showChecklists()->firstOrFail();
        $this->assertSame('yes', $list->checks['emergency_case']['value']);
        $this->assertSame('2 Barriers fehlen', $list->checks['barriers']['note']);
        $this->assertSame(['done' => 2, 'missing' => 1, 'total' => 13], $list->counts());
        $this->assertSame('Kim Tour', $list->promoter_rep);
        $this->assertSame(self::SIGNATURE, $list->promoter_rep_signature);
        $this->assertSame($admin->getFilamentName(), $list->created_by_name);

        $this->manager()
            ->assertCanSeeTableRecords([$list])
            ->assertTableColumnStateSet('checked', '2 / 13', $list)
            ->assertTableColumnStateSet('missing', '1 × Nein', $list);
    }

    public function test_nur_ja_oder_nein_und_gueltige_unterschrift(): void
    {
        $this->actingAs($this->admin());

        $this->manager()->callTableAction('create', data: [
            'checked_at' => '2026-10-09 23:40',
            'checks' => ['emergency_case' => ['value' => 'vielleicht']],
            'house_rep_signature' => 'javascript:alert(1)',
        ])->assertHasTableActionErrors(['checks.emergency_case.value', 'house_rep_signature']);
        $this->assertSame(0, EventShowChecklist::count());
    }

    public function test_bearbeiten_und_loeschen_nur_mit_event_operationen(): void
    {
        $list = $this->event->showChecklists()->create(['checked_at' => now(), 'checks' => ['bus_power' => ['value' => 'yes']]]);

        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        $this->manager(ViewEvent::class)
            ->assertCanSeeTableRecords([$list])
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $list)
            ->assertTableActionHidden('delete', $list)
            ->assertTableActionVisible('view', $list);

        $this->actingAs($this->userWith($this->role('einlass', ['events' => 'read', 'events_operations' => 'edit'])));
        $this->manager()
            ->callTableAction('edit', $list, data: ['checks' => ['bus_power' => ['value' => 'no', 'note' => 'Bus 2 noch am Netz']]])
            ->assertHasNoTableActionErrors();
        $this->assertSame('Bus 2 noch am Netz', $list->fresh()->checks['bus_power']['note']);

        $this->manager()->callTableAction('delete', $list);
        $this->assertModelMissing($list);
    }
}
