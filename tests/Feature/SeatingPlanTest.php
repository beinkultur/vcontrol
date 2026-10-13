<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\AssignmentRole;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\SeatingPlans\Pages\ManageSeatingPlans;
use App\Mail\DaysheetMail;
use App\Models\Event;
use App\Models\FieldOption;
use App\Models\SeatingPlan;
use App\Support\Daysheets;
use App\Support\ExternSheet;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Saalpläne: Standard-Pläne der Halle, Auswahl oder eigener Plan je Event, Anzeige für Externe. */
class SeatingPlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Storage::fake(SeatingPlan::DISK);
        foreach (['Innenraum', 'Oberrang A-D', 'Unterrang A-C'] as $area) {
            FieldOption::create(['field_key' => 'areas', 'value' => $area]);
        }
        foreach (['unbestuhlt (nur Innenraum)', 'teilbestuhlt', 'bestuhlt'] as $seating) {
            FieldOption::create(['field_key' => 'seating', 'value' => $seating]);
        }
    }

    /** @param  list<string>  $areas  @param  list<string>  $seating */
    private function plan(string $name = 'Konzert bestuhlt', array $areas = ['Innenraum', 'Unterrang A-C'], string $content = '%PDF-1.4 Saalplan', array $seating = ['bestuhlt']): SeatingPlan
    {
        $file = str($name)->slug() . '.pdf';
        Storage::disk(SeatingPlan::DISK)->put(SeatingPlan::DIRECTORY . '/' . $file, $content);

        return SeatingPlan::create(['name' => $name, 'path' => SeatingPlan::DIRECTORY . '/' . $file, 'original_name' => $file,
            'areas' => $areas, 'seating' => $seating]);
    }

    public function test_admin_legt_standard_saalplaene_an(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageSeatingPlans::class)
            ->callAction('create', data: [
                'name' => 'Konzert bestuhlt',
                'path' => UploadedFile::fake()->createWithContent('Konzert.pdf', '%PDF-1.4 Saalplan'),
                'areas' => ['Innenraum', 'Oberrang A-D'],
                'seating' => ['bestuhlt'],
            ])
            ->assertHasNoActionErrors();

        $plan = SeatingPlan::query()->sole();
        $this->assertSame('Konzert.pdf', $plan->original_name);
        $this->assertSame(['Innenraum', 'Oberrang A-D'], $plan->areas);
        $this->assertSame(['bestuhlt'], $plan->seating);
        $this->assertTrue($plan->isPdf());
        Storage::disk(SeatingPlan::DISK)->assertExists($plan->path);

        // Nur PDFs
        Livewire::test(ManageSeatingPlans::class)
            ->callAction('create', data: ['name' => 'Bild', 'path' => UploadedFile::fake()->image('plan.png')])
            ->assertHasActionErrors(['path']);

        // Die Pläne pflegt, wer die Stammdaten der Halle bearbeitet – nicht der Eventmanager
        $this->actingAs($this->userWith($this->role('eventmanager', ['events' => 'edit'])))
            ->get('/saalplaene')
            ->assertForbidden();
    }

    public function test_event_waehlt_standard_saalplan_und_passt_bereiche_und_bestuhlung_an(): void
    {
        $plan = $this->plan();
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek(), 'areas' => ['Oberrang A-D'], 'seating' => ['unbestuhlt (nur Innenraum)']]);
        $this->actingAs($this->userWith($this->role('eventmanager', ['events' => 'edit'])));

        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->assertSchemaStateSet(['seating_plan_source' => 'standard'])
            ->fillForm(['seating_plan_id' => $plan->id])
            ->assertSchemaStateSet(['areas' => ['Innenraum', 'Unterrang A-C'], 'seating' => ['bestuhlt']])
            // Tribüne zunächst gesperrt, Innenraum nur teilweise bestuhlt
            ->fillForm(['areas' => ['Innenraum'], 'seating' => ['teilbestuhlt']])
            ->call('save')
            ->assertHasNoFormErrors();

        $event->refresh();
        $this->assertSame($plan->id, $event->seating_plan_id);
        $this->assertSame(['Innenraum'], $event->areas);
        $this->assertSame(['teilbestuhlt'], $event->seating);
        $this->assertSame('Konzert bestuhlt', $event->seatingPlanLabel());
    }

    public function test_eigener_saalplan_loest_den_standard_ab_und_umgekehrt(): void
    {
        $plan = $this->plan();
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek(), 'seating_plan_id' => $plan->id]);
        $this->actingAs($this->admin());

        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm(['seating_plan_source' => 'custom'])
            ->fillForm(['seating_plan_path' => UploadedFile::fake()->createWithContent('Sonderplan.pdf', '%PDF-1.4 Sonder')])
            ->call('save')
            ->assertHasNoFormErrors();

        $event->refresh();
        $this->assertNull($event->seating_plan_id);
        $this->assertTrue($event->hasCustomSeatingPlan());
        $this->assertSame('Sonderplan.pdf', $event->seating_plan_original_name);
        $this->assertSame('application/pdf', $event->seating_plan_mime_type);
        $custom = (string) $event->seating_plan_path;
        Storage::disk(SeatingPlan::DISK)->assertExists($custom);

        // Zurück zum Standard: der eigene Plan verschwindet, auch von der Platte
        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->assertSchemaStateSet(['seating_plan_source' => 'custom'])
            ->fillForm(['seating_plan_source' => 'standard'])
            ->fillForm(['seating_plan_id' => $plan->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $event->refresh();
        $this->assertSame($plan->id, $event->seating_plan_id);
        $this->assertNull($event->seating_plan_path);
        $this->assertNull($event->seating_plan_original_name);
        Storage::disk(SeatingPlan::DISK)->assertMissing($custom);
    }

    public function test_pdf_nur_fuer_berechtigte_und_nur_echte_pdfs_im_browser(): void
    {
        $plan = $this->plan();
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek(), 'seating_plan_id' => $plan->id]);

        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        $this->get(route('seating-plans.pdf', $plan))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('events.seating-plan', $event))->assertOk();

        $this->actingAs($this->userWith($this->role('ohne', ['codes' => 'read'])));
        $this->get(route('seating-plans.pdf', $plan))->assertForbidden();
        $this->get(route('events.seating-plan', $event))->assertForbidden();

        // Kein PDF-Inhalt hinter der Endung: Download in der Sandbox statt Anzeige
        $fake = $this->plan('Falsch', [], '<html><body>kein PDF</body></html>');
        $response = $this->actingAs($this->admin())->get(route('seating-plans.pdf', $fake))->assertOk();
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_saalplan_in_externer_ansicht_und_im_daysheet(): void
    {
        Mail::fake();
        $plan = $this->plan();
        $event = Event::create(['title' => 'Konzert Nord', 'status' => 'bestätigt', 'starts_at' => now()->addDays(3),
            'seating_plan_id' => $plan->id, 'areas' => ['Innenraum'], 'seating' => ['bestuhlt']]);

        $sheet = ExternSheet::make($event, fn (): string => '');
        $this->assertSame([
            'file' => ['name' => 'Konzert bestuhlt', 'url' => route('events.seating-plan', $event)],
            'areas' => ['Innenraum'],
            'seating' => ['bestuhlt'],
        ], $sheet['seating_plan']);

        // Externe, die am Event beteiligt sind – sonst nicht
        $freelancer = $this->userWith($this->role('extern', ['events_extern' => 'read']));
        $freelancer->update(['account_type' => AccountType::Freelancer]);
        $event->assignments()->create(['role' => AssignmentRole::HouseRepEarly, 'assignee_type' => 'user', 'assignee_id' => $freelancer->id]);
        $other = Event::create(['title' => 'Andere', 'starts_at' => now()->addDays(4), 'seating_plan_id' => $plan->id]);
        $this->actingAs($freelancer->fresh())->get(route('events.seating-plan', $event))->assertOk();
        $this->get(route('events.seating-plan', $other))->assertForbidden();

        // Daysheet: über den Link mit Schlüssel, ohne Konto
        $this->actingAs($this->admin());
        Daysheets::send($event, ['buero@halle.de'], [], 'Daysheet {event}', "Hallo,\n{link}");
        preg_match('#/daysheet/([A-Za-z0-9]{48})#', Mail::sent(DaysheetMail::class)->last()->text, $match);

        $this->get('/daysheet/' . $match[1])
            ->assertOk()
            ->assertSee('Saalplan')
            ->assertSee(route('daysheet.seating-plan', $match[1]), false)
            ->assertSee('Konzert bestuhlt');
        $this->get(route('daysheet.seating-plan', $match[1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_genutzter_plan_wird_nicht_geloescht(): void
    {
        $plan = $this->plan();
        $admin = $this->admin();
        $this->assertTrue($admin->can('delete', $plan));

        Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek(), 'seating_plan_id' => $plan->id]);
        $this->assertFalse($admin->can('delete', $plan->fresh()));
    }

    public function test_buchhaltung_aendert_den_saalplan_in_der_leseansicht(): void
    {
        $plan = $this->plan();
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
        $action = TestAction::make('changeSeatingPlan')->schemaComponent('seatingPlanActions');

        $this->actingAs($this->userWith($this->role('buchhaltung', ['events' => 'read', 'buchhaltung' => 'edit'])));
        Livewire::test(ViewEvent::class, ['record' => $event->getRouteKey()])
            ->assertActionVisible($action)
            ->callAction($action, data: ['seating_plan_id' => $plan->id, 'areas' => ['Innenraum'], 'seating' => ['teilbestuhlt']])
            ->assertHasNoActionErrors();

        $event->refresh();
        $this->assertSame($plan->id, $event->seating_plan_id);
        $this->assertSame(['Innenraum'], $event->areas);
        $this->assertSame(['teilbestuhlt'], $event->seating);

        // Nur lesen ohne Buchhaltung: kein Knopf
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        Livewire::test(ViewEvent::class, ['record' => $event->getRouteKey()])
            ->assertDontSee('Saalplan ändern');
    }
}
