<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\OrderSlipsRelationManager;
use App\Filament\Resources\OrderSlips\Pages\ListOrderSlips;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Event;
use App\Models\OrderSlip;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class OrderSlipsTest extends TestCase
{
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=';

    private Event $event;

    private Article $wasser;

    private Article $bier;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->event = Event::create(['title' => 'Konzert', 'starts_at' => now()]);
        $getraenke = ArticleCategory::create(['name' => 'Getränke', 'sort_order' => 10]);
        $this->wasser = Article::create(['category_id' => $getraenke->id, 'name' => 'Wasser 0,5L', 'unit' => 'Kiste (20 Flaschen)', 'price' => 20]);
        $this->bier = Article::create(['category_id' => $getraenke->id, 'name' => 'Bier 0,33L', 'unit' => 'Kiste (24 Flaschen)', 'price' => 30]);
    }

    private function manager(string $page = EditEvent::class)
    {
        return Livewire::test(OrderSlipsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => $page]);
    }

    public function test_bestellschein_mit_positionen_und_unterschrift(): void
    {
        $this->actingAs($admin = $this->admin());

        $this->manager()->callTableAction('create', data: [
            'ordered_from' => 'Backstage Catering',
            'ordered_at' => '2026-10-09 18:00',
            'lines' => [
                ['article_id' => $this->wasser->id, 'quantity' => 2],
                ['article_id' => $this->bier->id, 'quantity' => 1.5],
            ],
            'signature' => self::SIGNATURE,
        ])->assertHasNoTableActionErrors();

        $slip = $this->event->orderSlips()->with('items')->firstOrFail();
        $this->assertSame('Backstage Catering', $slip->ordered_from);
        $this->assertSame($admin->getFilamentName(), $slip->created_by_name); // „Bei wem“
        $this->assertSame(85.0, $slip->total());                            // 2 × 20 + 1,5 × 30
        $this->assertSame(['Wasser 0,5L', 'Bier 0,33L'], $slip->items->pluck('article_name')->all());
        $this->assertSame(self::SIGNATURE, $slip->signature);

        // Spätere Preisänderung ändert den alten Schein nicht
        $this->wasser->update(['price' => 25]);
        $this->assertSame(85.0, $slip->fresh()->load('items')->total());
    }

    public function test_pflichtangaben_und_unterschrift_pruefen(): void
    {
        $this->actingAs($this->admin());

        $this->manager()->callTableAction('create', data: [
            'ordered_from' => '',
            'lines' => [],
            'signature' => '<script>alert(1)</script>',
        ])->assertHasTableActionErrors(['ordered_from' => 'required', 'lines', 'signature']);
        $this->assertSame(0, OrderSlip::count());
    }

    public function test_unterschrift_spaeter_und_abrechnen_nur_buchhaltung(): void
    {
        $slip = $this->event->orderSlips()->create(['ordered_from' => 'Bar 2', 'ordered_at' => now()]);

        // Event-Operationen dürfen unterschreiben lassen, aber nicht abrechnen
        $this->actingAs($this->userWith($this->role('einlass', ['events' => 'read', 'events_operations' => 'edit'])));
        $this->manager()
            ->assertTableActionVisible('sign', $slip)
            ->assertTableActionHidden('settle', $slip)
            ->callTableAction('sign', $slip, data: ['signature' => self::SIGNATURE]);
        $this->assertSame(self::SIGNATURE, $slip->fresh()->signature);

        $this->actingAs($this->userWith($this->role('buchhaltung', ['events' => 'read', 'buchhaltung' => 'edit', 'protokolle' => 'read'])));
        $this->manager(ViewEvent::class)->callTableAction('settle', $slip);
        $this->assertTrue($slip->fresh()->is_settled);

        Livewire::test(ListOrderSlips::class)
            ->assertCanSeeTableRecords([$slip])
            ->callTableAction('settle', $slip);
        $this->assertFalse($slip->fresh()->is_settled);
    }

    public function test_leserolle_sieht_aber_legt_nicht_an(): void
    {
        $slip = $this->event->orderSlips()->create(['ordered_from' => 'Bar 2', 'ordered_at' => now()]);
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));

        $this->manager(ViewEvent::class)
            ->assertCanSeeTableRecords([$slip])
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('sign', $slip)
            ->assertTableActionVisible('view', $slip);

        // Die Übersicht braucht das Recht „Protokolle“ wie in der PHP-Version
        $this->get('/bestellscheine')->assertForbidden();
    }
}
