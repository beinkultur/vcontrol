<?php

namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Promoter;
use App\Models\PromoterContact;
use App\Models\Trade;
use App\Support\EventProgress;
use App\Support\OnsiteContact;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/** Ansprechpartner vor Ort unter Planung › Kontakte (seit 19.10.2026 Vorname, Name, Telefon, E-Mail). */
class OnsiteContactTest extends TestCase
{
    private Promoter $promoter;

    private PromoterContact $lea;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->promoter = Promoter::create(['short_name' => 'KAN', 'name' => 'Kanal Konzerte GmbH']);
        $this->lea = $this->promoter->contacts()->create(['first_name' => 'Lea', 'last_name' => 'Sommer', 'role' => 'Booking',
            'phone' => '0170 111 222', 'email' => "lea@\u{200B}kanal.example", 'sort_order' => 1]);
    }

    public function test_freitext_wird_aufgeteilt(): void
    {
        $cases = [
            'Max Mustermann (0170-5555)' => ['Max', 'Mustermann', '0170-5555', null],
            'Max (0170-5555)' => [null, 'Max', '0170-5555', null],
            'Jan Ludwig (KAN)' => ['Jan', 'Ludwig (KAN)', null, null],
            'Mustermann, Max' => ['Max', 'Mustermann', null, null],
            'Produktionsleitung' => [null, 'Produktionsleitung', null, null],
            'Tel. +49 170 1234567, Anna-Lena Schmidt anna@example.com' => ['Anna-Lena', 'Schmidt', '+49 170 1234567', 'anna@example.com'],
            'Kim Tour Mobil: 0171/2345678' => ['Kim', 'Tour', '0171/2345678', null],
            'Ruben Alves (TPN), mail: ruben@tpn.example' => ['Ruben', 'Alves (TPN)', null, 'ruben@tpn.example'],
            '  ' => [null, null, null, null],
        ];
        foreach ($cases as $text => $expected) {
            $this->assertSame(array_combine(array_keys(OnsiteContact::FIELDS), $expected), OnsiteContact::parse($text), $text);
        }
        $this->assertSame([null, null, null, null], array_values(OnsiteContact::parse(null)));

        // Zurück (Migration rückwärts)
        $this->assertSame('Max Mustermann (0170-5555, max@example.com)', OnsiteContact::text('Max', 'Mustermann', '0170-5555', 'max@example.com'));
        $this->assertSame('0170-5555', OnsiteContact::text(null, null, '0170-5555', null));
        $this->assertNull(OnsiteContact::text(null, ' ', null, ''));
    }

    public function test_suche_in_veranstaltern_kontakten_und_gewerken(): void
    {
        $other = Promoter::create(['short_name' => 'NRD', 'name' => 'Nord Events']);
        $lena = $other->contacts()->create(['first_name' => 'Lena', 'last_name' => 'Sommerfeld', 'sort_order' => 1]);
        $archived = Promoter::create(['short_name' => 'ALT', 'name' => 'Alte Bühne', 'is_archived' => true]);
        $archived->contacts()->create(['first_name' => 'Lea', 'last_name' => 'Archiv', 'sort_order' => 1]);
        $master = Contact::create(['name' => 'Hallenmeister', 'phone' => '040 123 45']);
        Contact::create(['name' => 'Hallenmeister alt', 'is_archived' => true]);
        $trade = Trade::create(['short_name' => 'SNG', 'name' => 'Sicherheit Nord GmbH', 'phone' => '040 999', 'email' => 'dispo@sng.example']);

        // Ohne Suchbegriff: die Ansprechpartner des Veranstalters dieses Events
        $this->assertSame(['Veranstalter' => ['promoter_contact:' . $this->lea->id => 'Lea Sommer – Kanal Konzerte GmbH (Booking) · 0170 111 222 · lea@kanal.example']],
            OnsiteContact::suggestions($this->promoter->id));
        $this->assertSame([], OnsiteContact::suggestions(null));

        // Jedes Wort muss passen, der Veranstalter des Events zuerst, Archiviertes nicht
        $this->assertSame(['promoter_contact:' . $lena->id, 'promoter_contact:' . $this->lea->id],
            array_keys(OnsiteContact::search('somm', $other->id)['Veranstalter']));
        $this->assertSame(['promoter_contact:' . $this->lea->id], array_keys(OnsiteContact::search('kanal lea', null)['Veranstalter']));
        $this->assertSame([], OnsiteContact::search('archiv', null));
        $this->assertSame(['Kontakte' => ['contact:' . $master->id => 'Hallenmeister · 040 123 45']], OnsiteContact::search('HALLEN', null));
        $this->assertSame(['Gewerke' => ['trade:' . $trade->id => 'Sicherheit Nord GmbH (SNG) · 040 999 · dispo@sng.example']], OnsiteContact::search('sng', null));
        $this->assertArrayHasKey('Gewerke', OnsiteContact::search('nord', null));
        $this->assertArrayHasKey('Veranstalter', OnsiteContact::search('nord', null));

        // Übernehmen: Kopie der Daten, unsichtbare Zeichen aus dem Import entfernt
        $this->assertSame(['onsite_first_name' => 'Lea', 'onsite_last_name' => 'Sommer', 'onsite_phone' => '0170 111 222', 'onsite_email' => 'lea@kanal.example'],
            OnsiteContact::pick('promoter_contact:' . $this->lea->id));
        $this->assertSame(['onsite_first_name' => null, 'onsite_last_name' => 'Hallenmeister', 'onsite_phone' => '040 123 45', 'onsite_email' => null],
            OnsiteContact::pick('contact:' . $master->id));
        $this->assertSame(['onsite_first_name' => null, 'onsite_last_name' => 'Sicherheit Nord GmbH', 'onsite_phone' => '040 999', 'onsite_email' => 'dispo@sng.example'],
            OnsiteContact::pick('trade:' . $trade->id));
        $this->assertNull(OnsiteContact::pick('user:1'));
        $this->assertNull(OnsiteContact::pick('contact:999'));
        $this->assertNull(OnsiteContact::pick(null));
    }

    public function test_im_reiter_kontakte_uebernehmen_und_speichern(): void
    {
        $this->actingAs($this->admin());
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek(), 'promoter_id' => $this->promoter->id]);
        $master = Contact::create(['name' => 'Kim Tour']);

        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->assertFormFieldVisible('onsite_lookup')
            ->fillForm(['onsite_lookup' => 'promoter_contact:' . $this->lea->id])
            ->assertSchemaStateSet(['onsite_lookup' => null, 'onsite_first_name' => 'Lea', 'onsite_last_name' => 'Sommer',
                'onsite_phone' => '0170 111 222', 'onsite_email' => 'lea@kanal.example'])
            ->fillForm(['onsite_phone' => '0170 111 222 (nur Show-Tag)'])
            ->call('save')
            ->assertHasNoFormErrors();

        $event->refresh();
        $this->assertSame(['Lea', 'Sommer', '0170 111 222 (nur Show-Tag)', 'lea@kanal.example'],
            [$event->onsite_first_name, $event->onsite_last_name, $event->onsite_phone, $event->onsite_email]);
        $this->assertSame('Lea Sommer', $event->onsiteContactName());
        $this->assertTrue(EventProgress::planningAreas($event, 0, 0)['kontakte']);

        // Ein Kontakt hat nur einen Namen – er wird geteilt, was fehlt, wird geleert
        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm(['onsite_lookup' => 'contact:' . $master->id])
            ->assertSchemaStateSet(['onsite_first_name' => 'Kim', 'onsite_last_name' => 'Tour', 'onsite_phone' => null, 'onsite_email' => null])
            ->fillForm(['onsite_email' => 'keine adresse'])
            ->call('save')
            ->assertHasFormErrors(['onsite_email' => 'email']);

        // In der Lese-Ansicht ohne Suchfeld
        Livewire::test(ViewEvent::class, ['record' => $event->getRouteKey()])
            ->assertFormFieldHidden('onsite_lookup')
            ->assertSchemaStateSet(['onsite_last_name' => 'Sommer']);
    }

    public function test_alte_links_auf_personal_landen_in_kontakte(): void
    {
        $this->actingAs($this->admin());
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);

        $this->get("/events/{$event->id}/edit?phase=planung&bereich=personal")
            ->assertRedirect("/events/{$event->id}/edit?phase=planung&bereich=kontakte");
        $this->get("/events/{$event->id}/edit?phase=planung&bereich=kontakte")
            ->assertOk()
            ->assertSee('Ansprechpartner vor Ort')
            ->assertSee('Rollen');

        // Nur lesend: erst weiter in die Ansicht, dort in den neuen Bereich
        $this->actingAs($this->userWith($this->role('lesen', ['events' => 'read'])));
        $this->get("/events/{$event->id}?phase=planung&bereich=personal")
            ->assertRedirect("/events/{$event->id}?phase=planung&bereich=kontakte");

        // Wer das Event nicht sehen darf, bekommt keine Weiterleitung
        $this->actingAs($this->userWith($this->role('ohne', [])));
        $this->get("/events/{$event->id}?phase=planung&bereich=personal")->assertForbidden();
    }
}
