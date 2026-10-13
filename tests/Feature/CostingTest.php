<?php

namespace Tests\Feature;

use App\Filament\Resources\Costings\CostingResource;
use App\Filament\Resources\Costings\Pages\EditCosting;
use App\Filament\Resources\Costings\Pages\ViewCosting;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\CostingsRelationManager;
use App\Mail\CostingMail;
use App\Models\Costing;
use App\Models\Event;
use App\Models\PriceList;
use App\Models\Promoter;
use App\Support\CostingPdf;
use App\Support\Costings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Costing: aus der Preisliste, Faktoren anpassen, Posten ergänzen, als PDF verschicken, Fassungen. */
class CostingTest extends TestCase
{
    private Event $event;

    private PriceList $list;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->list = PriceList::create(['name' => 'Konzert-Preisliste 2025-09', 'valid_from' => '2025-09-01', 'notes' => 'Alle Preise zzgl. MwSt.']);
        // Abschnitt, Kostenpunkt, Einheit, Preis, Mindestbetrag, Faktor, PAX, im neuen Costing an
        $items = [
            ['Mietkosten', 'Grundmiete unbestuhlt', 'pauschal', 2800, null, 1, false, true],
            ['Mietkosten', 'Grundmiete vollbestuhlt HS', 'pauschal', 4300, null, null, false, false],
            ['Nebenkosten', 'Facility-Fee', 'pro verkauftem Ticket', 1.5, 2500, null, true, true],
            ['Nebenkosten', 'Verkehrsposten', 'pro Person/Stunde', 28.5, null, 4, false, true],
            ['Zusätzlich', 'Nacht-/Sonn-/Feiertagszuschlag', '%', 15, null, null, false, true],
            ['Weitere Kosten', 'Sicherheitsdienst', null, null, null, 1, false, true],
        ];
        foreach ($items as $index => [$section, $name, $unit, $price, $minimum, $factor, $pax, $active]) {
            $this->list->items()->create(['section' => $section, 'name' => $name, 'unit' => $unit, 'price' => $price,
                'minimum' => $minimum, 'default_factor' => $factor, 'factor_from_pax' => $pax, 'default_active' => $active, 'sort_order' => $index + 1]);
        }

        $promoter = Promoter::create(['name' => 'Konzertagentur']);
        $promoter->contacts()->create(['first_name' => 'Pia', 'last_name' => 'Booking', 'email' => 'Booking@Agentur.de']);
        $promoter->contacts()->create(['first_name' => 'Ohne', 'last_name' => 'Mail']);
        $this->event = Event::create(['title' => 'Konzert Nord', 'starts_at' => now()->addMonth(), 'promoter_id' => $promoter->id,
            'pax_expected' => 2000, 'price_list_id' => $this->list->id]);
    }

    private function eventmanager(): \App\Models\User
    {
        return $this->userWith($this->role('eventmanager', ['events' => 'edit']));
    }

    public function test_costing_aus_der_preisliste_des_events(): void
    {
        $this->actingAs($this->eventmanager());

        Livewire::test(CostingsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class])
            ->assertTableActionVisible('createCosting')
            ->callTableAction('createCosting', data: ['price_list_id' => $this->list->id])
            ->assertHasNoTableActionErrors();

        $costing = Costing::query()->sole();
        $this->assertSame(1, $costing->version);
        $this->assertSame($this->list->id, $costing->price_list_id);
        $factors = $costing->items->mapWithKeys(fn ($item): array => [$item->name => $item->factor])->all();
        $this->assertSame([
            'Grundmiete unbestuhlt' => 1.0,
            'Grundmiete vollbestuhlt HS' => 0.0,
            'Facility-Fee' => 2000.0,            // PAX erwartet
            'Verkehrsposten' => 4.0,
            'Nacht-/Sonn-/Feiertagszuschlag' => 0.0,
            'Sicherheitsdienst' => 1.0,
        ], $factors);
        $this->assertTrue($costing->items[0]->fixed_price);
        $this->assertFalse($costing->items[5]->fixed_price, 'ohne Preis in der Liste: Schätzung möglich');
        // Die Alternative startet ausgeschaltet, wie in der Preisliste vorgegeben
        $this->assertSame(
            ['Grundmiete vollbestuhlt HS'],
            $costing->items->reject(fn ($item): bool => $item->is_active)->pluck('name')->values()->all(),
        );

        // 2.800 + 2.000 × 1,50 + 4 × 28,50; Sicherheitsdienst nach Aufwand
        $this->assertSame(5914.0, $costing->net());
        $this->assertSame(1123.66, $costing->vat());
        $this->assertSame(7037.66, $costing->gross());
    }

    public function test_mindestbetrag_zuschlag_und_nach_aufwand(): void
    {
        $costing = Costing::createFor($this->event, $this->list);
        $fee = $costing->items->firstWhere('name', 'Facility-Fee');
        $fee->update(['factor' => 1000]);
        $this->assertSame(2500.0, $fee->amount(), '1.000 × 1,50 € wären 1.500 €, mindestens 2.500 €');

        $surcharge = $costing->items->firstWhere('name', 'Nacht-/Sonn-/Feiertagszuschlag');
        $surcharge->update(['factor' => 855]);
        $this->assertSame(128.25, $surcharge->amount(), '15 % auf 855 €');

        $this->assertNull($costing->items->firstWhere('name', 'Sicherheitsdienst')->amount());
        $this->assertSame(0.0, $costing->items->firstWhere('name', 'Grundmiete vollbestuhlt HS')->amount());
    }

    public function test_faktoren_anpassen_posten_ergaenzen_preise_der_liste_bleiben(): void
    {
        $costing = Costing::createFor($this->event, $this->list);
        $rent = $costing->items->firstWhere('name', 'Grundmiete unbestuhlt');
        $seated = $costing->items->firstWhere('name', 'Grundmiete vollbestuhlt HS');
        $security = $costing->items->firstWhere('name', 'Sicherheitsdienst');
        $this->actingAs($this->eventmanager());

        Livewire::test(EditCosting::class, ['record' => $costing->getRouteKey()])
            // Preise mit Tausenderpunkt, die der Preisliste fest
            ->assertSchemaStateSet(['items.record-' . $rent->id . '.price' => '2.800,00'])
            ->assertFormFieldIsDisabled('items.record-' . $rent->id . '.price')
            ->assertFormFieldIsDisabled('items.record-' . $rent->id . '.unit')
            ->set('data.items.record-' . $rent->id . '.factor', '0.5')
            // Auch am Formular vorbei bleiben Preis und Einheit der Preisliste
            ->set('data.items.record-' . $rent->id . '.fixed_price', false)
            ->set('data.items.record-' . $rent->id . '.price', '1')
            ->set('data.items.record-' . $rent->id . '.unit', 'je Stück')
            ->set('data.items.record-' . $security->id . '.price', '1.800,00')
            // Alternative ausblenden
            ->set('data.items.record-' . $seated->id . '.is_active', false)
            ->set('data.items.neu', ['is_active' => true, 'name' => 'Rabatt Stammkunde', 'description' => null, 'unit' => 'pauschal', 'price' => '-250,00', 'factor' => '1'])
            ->set('data.note', 'Ein- und Ausbau Hausbühne anteilig.')
            ->call('save')
            ->assertHasNoFormErrors();

        $costing->refresh();
        $rent->refresh();
        $this->assertSame(0.5, $rent->factor);
        $this->assertSame(2800.0, $rent->price);
        $this->assertSame('pauschal', $rent->unit);
        $this->assertSame(1400.0, $rent->amount());
        $this->assertSame(1800.0, $security->fresh()->price, 'Schätzung für einen Posten ohne Preis');
        $this->assertFalse($seated->fresh()->is_active);
        $extra = $costing->items->firstWhere('name', 'Rabatt Stammkunde');
        $this->assertFalse($extra->fixed_price);
        $this->assertNull($extra->price_list_item_id);
        $this->assertNull($extra->section, 'ergänzte Posten stehen im PDF unter „Weitere Posten“');
        $this->assertSame(-250.0, $extra->amount());
        $this->assertSame('Ein- und Ausbau Hausbühne anteilig.', $costing->note);
        // 1.400 + 3.000 + 114 + 1.800 – 250
        $this->assertSame(6064.0, $costing->net());
    }

    public function test_verschicken_mit_pdf_danach_neue_fassung(): void
    {
        Mail::fake();
        $costing = Costing::createFor($this->event, $this->list);
        $manager = $this->eventmanager();
        $this->actingAs($manager);

        $defaults = Costings::defaults($costing, $manager);
        $this->assertSame(['booking@agentur.de'], $defaults['to']);
        $this->assertSame([mb_strtolower($manager->email)], $defaults['bcc']);
        $this->assertStringContainsString('Konzert Nord', $defaults['subject']);

        Livewire::test(EditCosting::class, ['record' => $costing->getRouteKey()])
            ->callAction('sendCosting', data: [
                'to' => ['booking@agentur.de'],
                'bcc' => [$manager->email],
                'subject' => 'Costing {event}',
                'body' => "Hallo,\n\nanbei das Costing ({fassung}, Preisliste {preisliste}).",
            ])
            ->assertHasNoActionErrors()
            ->assertRedirect(CostingResource::getUrl('view', ['record' => $costing]));

        Mail::assertSent(CostingMail::class, function (CostingMail $mail) use ($costing): bool {
            $attachment = $mail->attachments()[0];

            return $mail->hasTo('booking@agentur.de')
                && $mail->subjectLine === 'Costing Konzert Nord'
                && str_contains($mail->text, 'Fassung 1, Preisliste Konzert-Preisliste 2025-09')
                && $mail->fileName === $costing->fileName()
                && str_starts_with($mail->pdf, '%PDF')
                && $attachment->as === $costing->fileName();
        });

        $costing->refresh();
        $this->assertTrue($costing->isSent());
        $this->assertSame(['booking@agentur.de'], $costing->sent_to);

        // Verschickt bleibt verschickt: Bearbeiten führt zur Ansicht, dort gibt es die neue Fassung
        $this->get(CostingResource::getUrl('edit', ['record' => $costing]))->assertRedirect(CostingResource::getUrl('view', ['record' => $costing]));
        $this->assertFalse($manager->can('update', $costing));
        $this->assertFalse($manager->can('delete', $costing));

        Livewire::test(ViewCosting::class, ['record' => $costing->getRouteKey()])
            ->callAction('duplicateCosting');
        $copy = Costing::query()->where('version', 2)->sole();
        $this->assertFalse($copy->isSent());
        $this->assertSame($costing->items->count(), $copy->items()->count());
        $this->assertTrue($manager->can('update', $copy));
    }

    public function test_ohne_empfaenger_kein_versand(): void
    {
        Mail::fake();
        $costing = Costing::createFor($this->event, $this->list);
        $this->actingAs($this->eventmanager());

        Livewire::test(EditCosting::class, ['record' => $costing->getRouteKey()])
            ->callAction('sendCosting', data: ['to' => [], 'bcc' => [], 'subject' => 'Costing', 'body' => 'Hallo'])
            ->assertHasActionErrors(['to']);

        Mail::assertNothingSent();
        $this->assertFalse($costing->fresh()->isSent());
    }

    public function test_pdf_mit_posten_und_summen(): void
    {
        $costing = Costing::createFor($this->event, $this->list);
        $pdf = CostingPdf::render($costing);
        $this->assertStringStartsWith('%PDF', $pdf);

        $html = view('costing-pdf', [
            'costing' => $costing, 'event' => $this->event, 'venue' => 'Inselpark Arena',
            'sections' => $costing->printedSections(), 'logo' => '',
        ])->render();
        $this->assertStringContainsString('Grundmiete unbestuhlt', $html);
        $this->assertStringContainsString('Nacht-/Sonn-/Feiertagszuschlag', $html, 'Faktor 0 steht im PDF, mit 0,00 €');
        $this->assertStringContainsString('0,00 €', $html);
        $this->assertStringNotContainsString('Grundmiete vollbestuhlt HS', $html, 'in der Preisliste vorgegeben: aus');
        $this->assertStringContainsString('mind. 2.500,00 €', $html);
        $this->assertStringContainsString('nach Aufwand', $html);
        $this->assertStringContainsString('5.914,00 €', $html);
        $this->assertStringContainsString('7.037,66 €', $html);
        $this->assertStringContainsString('Alle Preise zzgl. MwSt.', $html);

        // Ausgeschaltet: nicht im PDF, nicht in der Summe
        $costing->items->firstWhere('name', 'Grundmiete unbestuhlt')->update(['is_active' => false]);
        $costing->refresh();
        $this->assertSame(3114.0, $costing->net());
        $html = view('costing-pdf', [
            'costing' => $costing, 'event' => $this->event, 'venue' => 'Inselpark Arena',
            'sections' => $costing->printedSections(), 'logo' => '',
        ])->render();
        $this->assertStringNotContainsString('Grundmiete unbestuhlt', $html);
        $this->assertStringContainsString('3.114,00 €', $html);
    }

    public function test_buchhaltung_sieht_costing_leserollen_nicht(): void
    {
        $costing = Costing::createFor($this->event, $this->list);

        $this->actingAs($this->userWith($this->role('buchhaltung', ['events' => 'read', 'buchhaltung' => 'edit'])));
        $this->get(CostingResource::getUrl())->assertOk()->assertSee('Konzert Nord');
        $this->get(CostingResource::getUrl('view', ['record' => $costing]))->assertOk()->assertSee('Facility-Fee');
        $this->get(CostingResource::getUrl('edit', ['record' => $costing]))->assertRedirect(CostingResource::getUrl('view', ['record' => $costing]));
        $this->get(route('costings.pdf', $costing))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // Im Event: Reiter Costing, aber nichts anlegen
        $this->get(EventResource::getUrl('view', ['record' => $this->event]) . '?phase=buchung&bereich=costing')->assertOk()->assertSee('Fassung 1');
        Livewire::test(CostingsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => ViewEvent::class])
            ->assertTableActionHidden('createCosting');

        // Finanzdaten: Leserollen ohne Buchhaltung sehen kein Costing
        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        $this->get(CostingResource::getUrl())->assertForbidden();
        $this->get(route('costings.pdf', $costing))->assertForbidden();
        $this->get(EventResource::getUrl('view', ['record' => $this->event]))->assertOk()->assertDontSee('Fassung 1');
    }
}
