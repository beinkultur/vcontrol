<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageVenue;
use App\Filament\Resources\Accounting\Pages\ListAccounting;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\Event;
use App\Models\EventStage;
use App\Models\Setting;
use App\Support\StagePodests;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class EventStageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($this->admin());
    }

    public function test_podeste_wie_in_der_php_version(): void
    {
        // Beispiele aus dem Bestand, Summen dort von AppSheet bzw. der PHP-Version.
        $this->assertSame(62, StagePodests::calculate(['width' => '14.00', 'depth' => '8.00'])['total']);
        $this->assertSame(71, StagePodests::calculate([
            'width' => 14, 'depth' => 8, 'wing_sl_width' => 3, 'wing_sl_depth' => 4, 'wing_sr_width' => 2, 'wing_sr_depth' => 3,
        ])['total']);
        $this->assertSame(79, StagePodests::calculate([
            'width' => 14, 'depth' => 10, 'rollpodest_width' => 4, 'rollpodest_depth' => 3, 'extra_platforms' => 3,
        ])['total']);
        // Ohne Maße steht nur das Rollipodest in Standardgröße, 0 heißt: keins.
        $this->assertSame(6, StagePodests::calculate([])['total']);
        $this->assertSame(0, StagePodests::calculate(['rollpodest_width' => 0])['total']);
    }

    public function test_summe_aus_appsheet_bleibt_ohne_aenderung_der_masse(): void
    {
        $event = Event::create(['title' => 'Altbestand', 'starts_at' => now()]);
        DB::table('event_stages')->insert(['event_id' => $event->id, 'podest_total' => 77, 'notes' => 'Produktion baut Wing selbst']);
        $stage = EventStage::findOrFail($event->id);

        $stage->update(['stage_notes' => 'Bühne sauber machen!']);
        $this->assertSame(77, $stage->fresh()->podest_total);

        $stage->update(['width' => 14, 'depth' => 8]);
        $this->assertSame(62, $stage->fresh()->podest_total);
    }

    public function test_buehne_im_workspace_speichern(): void
    {
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);

        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm([
                'stage.width' => 14,
                'stage.depth' => 10,
                'stage.height' => '1.00',
                'stage.extra_platforms' => 3,
                'stage.stage_notes' => 'Egonase 2x3m',
            ])
            ->assertSee('Im Bestand – 7 Podeste Reserve.')
            ->call('save')
            ->assertHasNoFormErrors();

        $stage = $event->stage()->firstOrFail();
        $this->assertSame('14.00', $stage->width);
        $this->assertSame('1.00', $stage->height);
        $this->assertSame(79, $stage->podest_total);
        $this->assertSame('Egonase 2x3m', $stage->stage_notes);
    }

    public function test_sold_out_award_in_der_durchfuehrung(): void
    {
        $event = Event::create(['title' => 'Ausverkauft', 'starts_at' => now()->subDay()]);

        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->assertFormSet(['sold_out_award' => null])
            ->fillForm(['sold_out_award' => 1, 'stage.width' => 14, 'stage.depth' => 8])
            ->call('save')
            ->assertHasNoFormErrors();

        $stage = $event->stage()->firstOrFail();
        $this->assertTrue($stage->sold_out_award);
        $this->assertSame(62, $stage->podest_total);

        $form = Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()]);
        // Genau 1, nicht true: Nur dann ist „ja“ markiert.
        $this->assertSame(1, $form->get('data.sold_out_award'));
        $form
            ->fillForm(['sold_out_award' => 0])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($stage->fresh()->sold_out_award);
        $this->assertSame(1, EventStage::query()->count());
    }

    public function test_uebersicht_zeigt_buehne_checkliste_und_award(): void
    {
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()]);
        $event->stage()->create(['width' => 20, 'depth' => 10, 'height' => 1.0, 'sold_out_award' => true]);
        $event->checklist()->create(['hands' => 'yes', 'traffic' => 'na', 'house_rig_early' => true]);

        $this->get(EventResource::getUrl('view', ['record' => $event]))
            ->assertOk()
            ->assertSee('Bühne 20×10 H1 106P');

        Livewire::test(ViewEvent::class, ['record' => $event->getRouteKey()])
            ->assertSchemaStateSet([
                'checklist.hands' => 'yes',
                'checklist.traffic' => 'na',
                'house_rig_early' => 1,
                'sold_out_award' => 1,
            ]);
    }

    public function test_kurzform_in_der_liste(): void
    {
        Setting::put(Setting::PODEST_INVENTORY, '75');
        $stage = new EventStage(['width' => 14, 'depth' => 8, 'height' => 1.4, 'podest_total' => 62]);
        $this->assertSame(['text' => '14×8 H1,4 62P', 'alert' => false, 'height' => false, 'podests' => false], StagePodests::summary($stage));

        // Andere Höhe als 1,4 m (gelb) oder mehr Podeste als im Bestand (rot)
        $stage = new EventStage(['width' => 14, 'depth' => 10, 'height' => 1.0, 'podest_total' => 79]);
        $this->assertSame(['text' => '14×10 H1 79P', 'alert' => true, 'height' => true, 'podests' => true], StagePodests::summary($stage));
        $this->assertSame(['text' => '–', 'alert' => false, 'height' => false, 'podests' => false], StagePodests::summary(null));
    }

    public function test_bestand_und_mietanteil_je_halle(): void
    {
        Livewire::test(ManageVenue::class)
            ->assertFormSet(['podest_inventory' => 86, 'podest_included' => 62])
            ->fillForm(['venue_name' => 'Zweite Halle', 'podest_inventory' => 100, 'podest_included' => 40])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('100', Setting::lookup(Setting::PODEST_INVENTORY));
        $this->assertSame('40', Setting::lookup(Setting::PODEST_INCLUDED));
    }
}
