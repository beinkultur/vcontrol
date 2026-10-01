<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\HandoverProtocolsRelationManager;
use App\Models\Event;
use App\Models\HandoverProtocol;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class HandoverProtocolsTest extends TestCase
{
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=';

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->event = Event::create(['title' => 'Konzert', 'starts_at' => now()]);
    }

    private function manager()
    {
        return Livewire::test(HandoverProtocolsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class]);
    }

    public function test_uebergabe_mit_unterschrift_und_rueckgabe(): void
    {
        $this->actingAs($admin = $this->admin());
        $funk = InventoryCategory::create(['name' => 'Funk', 'sort_order' => 20]);
        $schluessel = InventoryCategory::create(['name' => 'Schlüssel', 'sort_order' => 30]);
        $funkgeraet = InventoryItem::create(['category_id' => $funk->id, 'name' => 'Funkgerät']);
        $backstage = InventoryItem::create(['category_id' => $schluessel->id, 'name' => 'Backstage-Schlüssel']);

        $this->manager()->callTableAction('create', data: [
            'handed_to' => 'Whitney Houston',
            'handed_at' => '2026-10-09 14:57',
            'items' => [$backstage->id, $funkgeraet->id],
            'signature' => self::SIGNATURE,
        ])->assertHasNoTableActionErrors();

        $protocol = $this->event->handoverProtocols()->with('items')->firstOrFail();
        $this->assertTrue($protocol->isOpen());
        $this->assertSame($admin->getFilamentName(), $protocol->created_by_name);
        $this->assertSame(['Funkgerät', 'Backstage-Schlüssel'], $protocol->items->pluck('item_name')->all()); // nach Kategorie sortiert
        $this->assertSame(self::SIGNATURE, $protocol->signature);

        $this->manager()->callTableAction('return', $protocol);
        $protocol->refresh();
        $this->assertSame(HandoverProtocol::RETURNED, $protocol->status);
        $this->assertNotNull($protocol->returned_at);
        $this->assertSame($admin->id, $protocol->returned_by);

        $this->manager()->assertTableActionHidden('return', $protocol);
    }

    public function test_empfaenger_und_artikel_sind_pflicht(): void
    {
        $this->actingAs($this->admin());

        $this->manager()->callTableAction('create', data: ['handed_to' => '', 'items' => []])
            ->assertHasTableActionErrors(['handed_to' => 'required', 'items' => 'required']);
    }

    public function test_ohne_schreibrecht_keine_uebergabe(): void
    {
        $protocol = $this->event->handoverProtocols()->create(['handed_to' => 'Michael', 'handed_at' => now(), 'status' => 'open']);
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));

        $this->manager()
            ->assertCanSeeTableRecords([$protocol])
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('return', $protocol)
            ->assertTableActionHidden('sign', $protocol);
    }
}
