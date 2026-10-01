<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageVenue;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\StagePlanPage;
use App\Http\Controllers\EventFileController;
use App\Models\Event;
use App\Models\EventStage;
use App\Support\StagePlan;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class StagePlanTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
        $this->event->stage()->create(['width' => 14, 'depth' => 8, 'height' => 1.4]);
    }

    public function test_raster_wie_in_der_php_version(): void
    {
        $plan = StagePlan::build(new EventStage(['width' => 14, 'depth' => 8, 'height' => 1.4]), $this->event);
        $this->assertCount(56, $plan['platforms']);
        $this->assertSame(['standard' => 56, 'in_house' => 0, 'rented' => 0], $plan['stats']['tier_counts']);
        $this->assertSame(80, $plan['stats']['available']); // 86 minus 6 fürs Rollipodest
        $this->assertSame(1.2, $plan['stairs'][0]['w']);    // Treppe = Bühnenhöhe minus 0,2 m
        $this->assertSame('IPA stage 14×8×1.4m', $plan['subtitle']);

        // 16×10 mit Wing SL 3×4: Tiefe und Seitenstreifen aus dem Hausbestand, die Wing angemietet
        $plan = StagePlan::build(new EventStage([
            'width' => 16, 'depth' => 10, 'height' => 1.0, 'wing_sl_width' => 3, 'wing_sl_depth' => 4,
        ]), $this->event);
        $this->assertSame(['standard' => 56, 'in_house' => 24, 'rented' => 6], $plan['stats']['tier_counts']);
        $this->assertSame(86, $plan['stats']['plan_used']);
        $this->assertSame(92, $plan['stats']['total_used']);
        $this->assertTrue($plan['stats']['over_limit']);
        $this->assertSame(6, $plan['stats']['additional']);
        $this->assertSame('IPA stage 16×10×1.0m + wing SL 3×4', $plan['subtitle']);
        $this->assertStringContainsString('+6 angemietet nötig', StagePlan::toSvg($plan));
    }

    public function test_standardbuehne_und_hausbestand_der_halle(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(ManageVenue::class)
            ->fillForm([
                'venue_name' => 'Zweite Halle',
                'stage_base_width' => 12, 'stage_base_depth' => 6, 'stage_house_2x1' => 10, 'stage_house_1x1' => 0,
                'stage_roll_width' => 0, 'stage_roll_depth' => 0, 'stage_height' => '1.0',
                'stage_label' => 'Halle 2 stage', 'stage_boundary' => '8',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        // 14×8 auf einer 12×6-Standardbühne: 36 Standard, 2 m Tiefe und je 1 m Seitenstreifen
        // dazu (12 + 8 Podeste), davon die ersten 10 aus dem Hausbestand
        $plan = StagePlan::build(new EventStage(['width' => 14, 'depth' => 8, 'height' => 1.0]), $this->event);
        $this->assertSame(['standard' => 36, 'in_house' => 10, 'rented' => 10], $plan['stats']['tier_counts']);
        $this->assertSame(0, $plan['stats']['roll_reserved']);
        $this->assertSame('Halle 2 stage 14×8×1.0m', $plan['subtitle']);
        $this->assertSame(4.5, $plan['room']['boundary_left']);

        $svg = StagePlan::toSvg($plan);
        $this->assertStringContainsString('standard stage 12x6', $svg);
        $this->assertStringContainsString('up to 10 additional platforms', $svg);
    }

    public function test_seite_mit_einstellungen_und_zeichnung(): void
    {
        $this->actingAs($this->admin())
            ->get(EventResource::getUrl('stage-plan', ['record' => $this->event]))
            ->assertOk()
            ->assertSee('Plan-Einstellungen')
            ->assertSee('<svg', escape: false)
            ->assertSee('Aktualisieren')
            ->assertSee('IPA stage 14×8×1.4m');
    }

    public function test_einstellungen_speichern(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(StagePlanPage::class, ['record' => $this->event->getRouteKey()])
            ->assertSet('data.backwall_cm', 160)
            ->assertSet('data.wing_sl_offset', 1)
            ->set('data.backwall_cm', 140)
            ->set('data.stair_sl_offset', 2)
            ->call('save')
            ->assertHasNoErrors();

        $stage = $this->event->stage()->firstOrFail();
        $this->assertSame(140, $stage->backwall_cm);
        $this->assertSame(2, $stage->stair_sl_offset);
        $this->assertSame(62, $stage->podest_total); // Einstellungen ändern die Podest-Summe nicht
    }

    public function test_leserolle_sieht_den_plan_aendert_ihn_nicht(): void
    {
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));

        $this->get(EventResource::getUrl('stage-plan', ['record' => $this->event]))
            ->assertOk()
            ->assertDontSee('Aktualisieren');

        Livewire::test(StagePlanPage::class, ['record' => $this->event->getRouteKey()])
            ->set('data.backwall_cm', 0)
            ->call('save')
            ->assertForbidden();
        $this->assertSame(160, $this->event->stage()->firstOrFail()->backwall_cm);
    }

    public function test_druck_und_svg(): void
    {
        $this->get(route('events.stage-plan-print', $this->event))->assertRedirect();

        $this->actingAs($this->userWith($this->role('buchhaltung', ['buchhaltung' => 'read'])));
        $this->get(route('events.stage-plan-print', $this->event))->assertForbidden();
        $this->get(route('events.stage-plan-svg', $this->event))->assertForbidden();
        $this->get(EventResource::getUrl('stage-plan', ['record' => $this->event]))->assertForbidden();

        $this->actingAs($this->admin());
        $this->get(route('events.stage-plan-print', $this->event))
            ->assertOk()
            ->assertSee(StagePlan::documentTitle($this->event))
            ->assertSee('<svg', escape: false);
        $this->get(route('events.stage-plan-svg', $this->event))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8')
            ->assertHeader('Content-Security-Policy', EventFileController::SANDBOX);
    }

    public function test_verlinkt_im_workspace(): void
    {
        $this->actingAs($this->admin())
            ->get(EventResource::getUrl('edit', ['record' => $this->event]))
            ->assertOk()
            ->assertSee(EventResource::getUrl('stage-plan', ['record' => $this->event]))
            ->assertSee('Bühnenplan anzeigen');
    }
}
