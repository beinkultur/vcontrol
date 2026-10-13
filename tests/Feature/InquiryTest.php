<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Inquiries\Pages\CreateInquiry;
use App\Filament\Resources\Inquiries\Pages\ViewInquiry;
use App\Models\Event;
use App\Models\Inquiry;
use App\Models\Promoter;
use App\Models\User;
use App\Support\Inquiries;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Anfragen (Optionen): Rang je Tag nach Eingang, belegte Tage, bestätigen (wird ein Event), zurückziehen. */
class InquiryTest extends TestCase
{
    private Promoter $promoter;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->travelTo(Carbon::parse('2026-10-14 10:00'));
        $this->promoter = Promoter::create(['name' => 'Konzertagentur Nord', 'short_name' => 'KAN']);
    }

    /** @param  list<string>  $days */
    private function inquiry(string $title, array $days, string $created, string $status = Inquiry::OPEN): Inquiry
    {
        $inquiry = Inquiry::create(['title' => $title, 'promoter_id' => $this->promoter->id, 'status' => $status]);
        $inquiry->forceFill(['created_at' => $created])->save();
        foreach ($days as $day) {
            $inquiry->dates()->create(['option_date' => $day]);
        }

        return $inquiry->fresh();
    }

    private function editor(bool $events = true): User
    {
        return $this->userWith($this->role('buero', ['anfragen' => 'edit', 'events' => $events ? 'edit' : 'read', 'kalender' => 'read']));
    }

    public function test_rang_nach_eingang_und_belegte_tage(): void
    {
        $first = $this->inquiry('Winter Rave', ['2026-11-14', '2026-11-21'], '2026-10-01 09:00');
        $second = $this->inquiry('Hochzeitsmesse', ['2026-11-14'], '2026-10-02 09:00');
        $this->inquiry('Zurückgezogen', ['2026-11-14'], '2026-09-01 09:00', Inquiry::WITHDRAWN);
        Event::create(['title' => 'Herbstfest', 'status' => 'bestätigt', 'starts_at' => '2026-11-21 00:00:00', 'ends_at' => '2026-11-22 00:00:00']);
        // Fraglich und abgeschlossen belegen nichts
        Event::create(['title' => 'Vielleicht', 'status' => 'fraglich', 'starts_at' => '2026-11-14 00:00:00']);
        Event::create(['title' => 'Alt', 'status' => 'bestätigt', 'closed' => true, 'starts_at' => '2026-11-14 00:00:00']);

        $this->assertSame(['2026-11-14' => [$first->id => 1, $second->id => 2], '2026-11-21' => [$first->id => 1]],
            Inquiries::ranks(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')));
        $this->assertSame(['2026-11-21' => true], Inquiries::blockedDays(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')));

        $slots = Inquiries::slots($first);
        $this->assertSame('1️⃣ Option', $slots[$first->dates[0]->id]['label']);
        $this->assertFalse($slots[$first->dates[1]->id]['available']);
        $this->assertSame('2️⃣ Option', Inquiries::slots($second)[$second->dates[0]->id]['label']);
        $this->assertSame('7. Option', Inquiries::rankLabel(7));
        $this->assertSame('Winter Rave (7. Opt.)', Inquiries::barTitle('Winter Rave', 7));

        $this->actingAs($this->editor());
        $this->get('/anfragen')->assertOk()->assertSee('Winter Rave')->assertSee('Hochzeitsmesse')->assertDontSee('Zurückgezogen</', false);
        $this->get('/anfragen/' . $first->id)->assertOk()->assertSee('1️⃣ Option')->assertSee('Nicht verfügbar');
    }

    public function test_anlegen_mit_wunschterminen(): void
    {
        $this->actingAs($user = $this->editor());
        $undo = Repeater::fake();

        Livewire::test(CreateInquiry::class)
            ->assertSchemaStateSet(['option_deadline' => '2026-11-11'])
            ->fillForm([
                'title' => 'Firmenfeier Nordlicht',
                'promoter_id' => $this->promoter->id,
                'dates' => [['option_date' => '2026-12-04'], ['option_date' => '2026-12-05']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $undo();

        $inquiry = Inquiry::query()->where('title', 'Firmenfeier Nordlicht')->sole();
        $this->assertSame(Inquiry::OPEN, $inquiry->status);
        $this->assertSame(['2026-12-04', '2026-12-05'], $inquiry->dates->map(fn ($date) => $date->option_date->toDateString())->all());
        $this->assertSame($user->getFilamentName(), $inquiry->created_by_name);
    }

    public function test_bestaetigen_legt_ein_event_an(): void
    {
        $inquiry = $this->inquiry('Winter Rave', ['2026-11-14', '2026-11-21'], '2026-10-01 09:00');
        $inquiry->update(['event_type1' => 'Konzert', 'comment' => 'Mit Nebel', 'seating' => ['Stehplätze']]);
        Event::create(['title' => 'Herbstfest', 'status' => 'bestätigt', 'starts_at' => '2026-11-21 00:00:00', 'ends_at' => '2026-11-22 00:00:00']);
        [$free, $blocked] = $inquiry->dates->all();
        $this->actingAs($this->editor());

        // Belegter Termin: nicht wählbar
        Livewire::test(ViewInquiry::class, ['record' => $inquiry->getRouteKey()])
            ->callAction('confirmInquiry', data: ['date_id' => $blocked->id])
            ->assertHasActionErrors(['date_id']);
        $this->assertTrue($inquiry->fresh()->isOpen());

        $page = Livewire::test(ViewInquiry::class, ['record' => $inquiry->getRouteKey()])
            ->callAction('confirmInquiry', data: ['date_id' => $free->id])
            ->assertHasNoActionErrors();

        $event = Event::query()->where('title', 'Winter Rave')->sole();
        $page->assertRedirect(EventResource::getUrl('edit', ['record' => $event]));
        $this->assertSame('bestätigt', $event->status);
        $this->assertSame('2026-11-14 00:00', $event->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-15 00:00', $event->ends_at->format('Y-m-d H:i'));
        $this->assertSame($this->promoter->id, $event->promoter_id);
        $this->assertSame('Konzert', $event->event_type1);
        $this->assertSame('Mit Nebel', $event->description);

        $inquiry->refresh();
        $this->assertSame(Inquiry::CONFIRMED, $inquiry->status);
        $this->assertSame($event->id, $inquiry->event_id);
        $this->assertSame($free->id, $inquiry->confirmed_date_id);

        // Danach: nicht mehr änderbar, der Tag ist belegt
        $this->get('/anfragen/' . $inquiry->id . '/edit')->assertForbidden();
        $this->assertSame(['2026-11-14' => true, '2026-11-21' => true], Inquiries::blockedDays(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')));
    }

    public function test_bestaetigen_braucht_events_bearbeiten(): void
    {
        $inquiry = $this->inquiry('Winter Rave', ['2026-11-14'], '2026-10-01 09:00');
        $this->actingAs($this->editor(events: false));

        Livewire::test(ViewInquiry::class, ['record' => $inquiry->getRouteKey()])
            ->assertActionHidden('confirmInquiry')
            ->assertActionVisible('withdrawInquiry');
    }

    public function test_zurueckziehen(): void
    {
        $inquiry = $this->inquiry('Winter Rave', ['2026-11-14'], '2026-10-01 09:00');
        $this->actingAs($this->editor());

        Livewire::test(ViewInquiry::class, ['record' => $inquiry->getRouteKey()])
            ->callAction('withdrawInquiry')
            ->assertActionHidden('confirmInquiry')
            ->assertActionHidden('withdrawInquiry');

        $this->assertSame(Inquiry::WITHDRAWN, $inquiry->fresh()->status);
        $this->assertSame([], Inquiries::ranks(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')));
    }

    public function test_rechte(): void
    {
        $inquiry = $this->inquiry('Winter Rave', ['2026-11-14'], '2026-10-01 09:00');

        $this->actingAs($this->userWith($this->role('team', ['events' => 'edit', 'kalender' => 'read'])));
        $this->get('/anfragen')->assertForbidden();
        $this->get('/anfragen/' . $inquiry->id)->assertForbidden();

        $this->actingAs($this->userWith($this->role('lesen', ['anfragen' => 'read'])));
        $this->get('/anfragen')->assertOk()->assertSee('Winter Rave');
        $this->get('/anfragen/create')->assertForbidden();
        $this->get('/anfragen/' . $inquiry->id . '/edit')->assertForbidden();
        Livewire::test(ViewInquiry::class, ['record' => $inquiry->getRouteKey()])
            ->assertActionHidden('confirmInquiry')
            ->assertActionHidden('withdrawInquiry');
    }
}
