<?php

namespace Tests\Feature;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Room;
use App\Models\Setting;
use App\Support\AuditPresenter;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class AuditTest extends TestCase
{
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    private function last(string $subject): AuditLog
    {
        return AuditLog::query()->where('subject', $subject)->latest('id')->firstOrFail();
    }

    public function test_anlegen_aendern_loeschen_mit_vorher_nachher(): void
    {
        $this->actingAs($admin = $this->admin());

        $event = Event::create(['title' => 'Konzert', 'starts_at' => '2026-10-09 20:00:00']);
        $created = $this->last('events');
        $this->assertSame('created', $created->action);
        $this->assertSame('Konzert', $created->new_values['title']);
        $this->assertSame($admin->getFilamentName(), $created->user_name);
        $this->assertSame($event->id, $created->event_id);

        $event->update(['title' => 'Konzert (Zusatzshow)']);
        $event->finance()->create(['rent' => 1000]);
        $event->finance->update(['rent' => 1200]);

        $updated = AuditLog::query()->where('subject', 'events')->where('action', 'updated')->firstOrFail();
        $this->assertSame(['title' => 'Konzert'], $updated->old_values);
        $this->assertSame(['title' => 'Konzert (Zusatzshow)'], $updated->new_values);
        $this->assertSame('Titel: Konzert → Konzert (Zusatzshow)', AuditPresenter::summary($updated));

        $rent = $this->last('event_finances');
        $this->assertSame($event->id, $rent->event_id);
        $this->assertSame('Konzert (Zusatzshow)', $rent->subject_label);
        $this->assertEquals(1000, $rent->old_values['rent']);
        $this->assertEquals(1200, $rent->new_values['rent']);
        $this->assertSame('Miete', AuditPresenter::changes($rent)[0]['field']);

        $note = $event->notes()->create(['subject' => 'Catering', 'body' => 'Vegan']);
        $note->delete();
        $deleted = $this->last('event_notes');
        $this->assertSame('deleted', $deleted->action);
        $this->assertSame('Vegan', $deleted->old_values['body']);
        $this->assertSame($event->id, $deleted->event_id);
    }

    public function test_geheimes_bleibt_geheim(): void
    {
        $this->actingAs($admin = $this->admin());

        $event = Event::create(['title' => 'Konzert', 'starts_at' => now(), 'wlan_password' => 'streng-geheim']);
        $this->assertSame('***', $this->last('events')->new_values['wlan_password']);

        $admin->update(['password' => 'neues-passwort-123']);
        $this->assertSame(['password' => '***'], $this->last('users')->new_values);

        $event->orderSlips()->create(['ordered_from' => 'Bar', 'ordered_at' => now(), 'signature' => self::SIGNATURE]);
        $this->assertSame('[Unterschrift]', $this->last('order_slips')->new_values['signature']);

        Setting::put(Setting::CALENDAR_FEED_TOKEN, 'abcdef');
        $this->assertSame('***', $this->last('settings')->new_values['value']);

        $this->assertStringNotContainsString('streng-geheim', AuditLog::query()->pluck('new_values')->toJson());
        $this->assertStringNotContainsString('abcdef', AuditLog::query()->pluck('new_values')->toJson());
    }

    public function test_nur_zeitstempel_ist_keine_aenderung(): void
    {
        $this->actingAs($this->admin());
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()]);
        $count = AuditLog::count();

        $event->touch();
        $event->update(['title' => 'Konzert']);

        $this->assertSame($count, AuditLog::count());
    }

    public function test_rollen_raeume_und_personal_auch_ohne_eloquent(): void
    {
        $this->actingAs($admin = $this->admin());
        $user = $this->userWith();
        $role = $this->role('kasse', ['events' => 'read']);

        $user->roles()->sync([$role->id]);
        $assigned = $this->last('role_user');
        $this->assertSame('created', $assigned->action);
        $this->assertSame($user->getFilamentName() . ' · Kasse', $assigned->subject_label);
        $user->roles()->sync([]);
        $this->assertSame('deleted', $this->last('role_user')->action);

        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()->addWeek()]);
        $lounge = Room::create(['name' => 'Lounge', 'sort_order' => 10]);
        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm(['room_' . $lounge->id => 'backstage'])
            ->call('save')
            ->assertHasNoFormErrors();

        $room = $this->last('event_room');
        $this->assertSame($event->id, $room->event_id);
        $this->assertSame([['field' => 'Raum', 'old' => '–', 'new' => 'Lounge'], ['field' => 'Nutzung', 'old' => '–', 'new' => 'Backstage']], AuditPresenter::changes($room));

        // Personal leeren: einzeln gelöscht, also protokolliert
        $event->assignments()->create(['role' => 'pl', 'assignee_type' => 'user', 'assignee_id' => $admin->id]);
        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm(['role_pl' => null])
            ->call('save');
        $this->assertTrue(AuditLog::query()->where('subject', 'event_assignments')->where('action', 'deleted')->exists());
    }

    public function test_protokoll_nur_mit_recht_audit_und_verlauf_je_event(): void
    {
        $this->actingAs($this->admin());
        $konzert = Event::create(['title' => 'Konzert', 'starts_at' => now()]);
        $messe = Event::create(['title' => 'Messe', 'starts_at' => now()]);

        Livewire::test(EditEvent::class, ['record' => $konzert->getRouteKey()])
            ->assertActionVisible('history')
            ->assertActionHasUrl('history', url('/audit') . '?' . http_build_query(['filters' => ['event' => ['value' => $konzert->id]]]));
        Livewire::withQueryParams(['filters' => ['event' => ['value' => $konzert->id]]])
            ->test(ListAuditLogs::class)
            ->assertCanSeeTableRecords(AuditLog::query()->where('event_id', $konzert->id)->get())
            ->assertCanNotSeeTableRecords(AuditLog::query()->where('event_id', $messe->id)->get());
        $konzert->update(['title' => 'Konzert (verlegt)']);
        $change = AuditLog::query()->where('event_id', $konzert->id)->where('action', 'updated')->firstOrFail();
        Livewire::test(ListAuditLogs::class)
            ->assertSee('Titel: Konzert → Konzert (verlegt)')
            ->assertTableActionVisible('view', $change);
        // Detailansicht: Filament rendert den Dialog im Test nicht mit, daher die Vorlage direkt
        $html = view('filament.audit.changes', ['rows' => AuditPresenter::changes($change), 'action' => $change->action])->render();
        $this->assertStringContainsString('Vorher', $html);
        $this->assertStringContainsString('Konzert (verlegt)', $html);
        Livewire::test(ListAuditLogs::class)
            ->filterTable('event', $konzert->id)
            ->assertCanSeeTableRecords(AuditLog::query()->where('event_id', $konzert->id)->get())
            ->assertCanNotSeeTableRecords(AuditLog::query()->where('event_id', $messe->id)->get());

        $this->actingAs($this->userWith($this->role('eventmanager', ['events' => 'edit', 'audit' => 'read'])));
        $this->get('/audit')->assertOk();

        $this->actingAs($this->userWith($this->role('catering', ['events' => 'read'])));
        $this->get('/audit')->assertForbidden();
        Livewire::test(ViewEvent::class, ['record' => $konzert->getRouteKey()])->assertActionHidden('history');
    }

    public function test_jeder_sieht_nur_eintraege_aus_seinen_bereichen(): void
    {
        $this->actingAs($this->admin());
        $event = Event::create(['title' => 'Konzert', 'starts_at' => now()]);
        $event->finance()->create(['rent' => 1000]);
        $eventLog = $this->last('events');
        $financeLog = $this->last('event_finances');
        $planer = $this->userWith($this->role('eventmanager', ['events' => 'edit', 'audit' => 'read']));
        $kasse = $this->userWith($this->role('buchhaltung', ['events' => 'read', 'buchhaltung' => 'read', 'audit' => 'read']));
        $userLog = $this->last('users');

        // Ohne Buchhaltung keine Miete, ohne Benutzerverwaltung keine Konten
        $this->actingAs($planer);
        Livewire::test(ListAuditLogs::class)
            ->assertCanSeeTableRecords([$eventLog])
            ->assertCanNotSeeTableRecords([$financeLog, $userLog])
            ->assertDontSee('Miete');
        $this->assertArrayNotHasKey('event_finances', AuditPresenter::subjectOptions($planer));

        $this->actingAs($kasse);
        Livewire::test(ListAuditLogs::class)
            ->assertCanSeeTableRecords([$eventLog, $financeLog])
            ->assertCanNotSeeTableRecords([$userLog]);

        $this->actingAs($this->admin());
        Livewire::test(ListAuditLogs::class)->assertCanSeeTableRecords([$eventLog, $financeLog, $userLog]);
        $this->assertNull(AuditPresenter::visibleSubjects($this->admin()));

        // IP und Browser nicht im Livewire-Zustand (Detaildialog füllt aus attributesToArray)
        $this->assertArrayNotHasKey('ip_address', $financeLog->attributesToArray());
        $this->assertArrayNotHasKey('user_agent', $financeLog->attributesToArray());
    }
}
