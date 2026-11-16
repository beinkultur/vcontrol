<?php

namespace Tests\Feature;

use App\Access\Module;
use App\Models\AdvancingTemplate;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventAdvancing;
use App\Models\Setting;
use App\Support\AdvancingCatalog;
use InvalidArgumentException;
use Tests\TestCase;

class AdvancingCatalogTest extends TestCase
{
    private const LKH = 'database/advancing/lkh-arena-kultur.json';

    /** @return array<string, mixed> */
    private function lkh(): array
    {
        return json_decode((string) file_get_contents(base_path(self::LKH)), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param  list<array<string, mixed>>  $items */
    private function catalog(array $items, string $name = 'Test'): array
    {
        return ['name' => $name, 'intro' => 'Einleitung', 'structure' => [['title' => 'Abschnitt', 'items' => $items]]];
    }

    public function test_lkh_katalog_ist_gueltig_und_ohne_personen(): void
    {
        $data = $this->lkh();
        $this->assertSame([], AdvancingCatalog::errors($data['structure']));

        $template = AdvancingCatalog::import($data);
        $this->assertSame('Kultur', $template->name);
        $items = collect($template->structure)->flatMap(fn (array $section): array => $section['items']);
        $this->assertCount(46, $items->where('type', '!=', 'info'));
        $this->assertCount(4, $items->where('type', 'info'));
        $this->assertCount(46, $template->fresh()->questions());

        // Keine Mailadressen oder Telefonnummern aus dem Google-Formular im Repo
        $raw = (string) file_get_contents(base_path(self::LKH));
        $this->assertStringNotContainsString('@', $raw);
        $this->assertDoesNotMatchRegularExpression('/\d{4,}\s?\d{3,}/', $raw);
        // Die Ansprechpersonen der Venue sind Personenfragen ohne feste Auswahl
        $persons = $items->where('type', 'person');
        $this->assertCount(6, $persons);
        $this->assertTrue($persons->every(fn (array $item): bool => ($item['locked'] ?? false) && !isset($item['options'])));
    }

    public function test_ungueltige_struktur_wird_abgelehnt(): void
    {
        $errors = AdvancingCatalog::errors([['title' => 'A', 'items' => [
            ['key' => 'doppelt', 'type' => 'text', 'label' => 'Eins'],
            ['key' => 'doppelt', 'type' => 'text', 'label' => 'Zwei'],
            ['key' => 'datei', 'type' => 'upload', 'label' => 'Rider'],
            ['key' => 'wahl', 'type' => 'choice', 'label' => 'Wahl'],
            ['key' => 'gebunden', 'type' => 'text', 'label' => 'X', 'binding' => 'event.foo'],
            ['key' => 'Groß Schreibung', 'type' => 'text', 'label' => 'Y'],
            ['type' => 'info'],
        ]]]);
        $this->assertCount(6, $errors);
        $text = implode("\n", $errors);
        foreach (['doppelt', 'upload', 'wahl', 'event.foo', 'Groß Schreibung', 'Infotext'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->assertNotSame([], AdvancingCatalog::errors(['kein Abschnitt']));
        $this->assertNotSame([], AdvancingCatalog::errors([['title' => 'ohne Fragen']]));

        try {
            AdvancingCatalog::import($this->catalog([['key' => 'x', 'type' => 'upload', 'label' => 'X']]));
            $this->fail('Ungültiger Katalog wurde importiert');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('upload', $exception->getMessage());
        }
        $this->assertSame(0, AdvancingTemplate::query()->count());
    }

    public function test_neuer_import_ersetzt_gleichnamigen_katalog(): void
    {
        $first = AdvancingCatalog::import($this->catalog([['key' => 'alt', 'type' => 'text', 'label' => 'Alt']]));
        $second = AdvancingCatalog::import($this->catalog([['key' => 'neu', 'type' => 'text', 'label' => 'Neu']]));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AdvancingTemplate::query()->count());
        $this->assertSame(['neu'], array_keys($second->fresh()->questions()));
    }

    public function test_neuer_import_aendert_laufende_advancings_nicht(): void
    {
        $template = AdvancingCatalog::import($this->catalog([['key' => 'busse', 'type' => 'number', 'label' => 'Busse']]));
        $event = Event::create(['title' => 'Konzert', 'status' => 'bestätigt', 'starts_at' => '2026-12-05 20:00:00']);
        $advancing = EventAdvancing::create([
            'event_id' => $event->id,
            'advancing_template_id' => $template->id,
            'template_name' => $template->name,
            'structure' => $template->structure,
            'answers' => ['busse' => '2'],
        ]);

        AdvancingCatalog::import($this->catalog([['key' => 'pkw', 'type' => 'number', 'label' => 'PKW']]));

        $advancing->refresh();
        $this->assertSame(['busse'], array_keys($advancing->questions()));
        $this->assertSame(['busse' => '2'], $advancing->answers);
    }

    public function test_befehl_importiert_und_meldet_fehler(): void
    {
        $this->artisan('vc:advancing-catalog', ['action' => 'import', 'file' => self::LKH])
            ->expectsOutputToContain('Katalog „Kultur“ importiert: 46 Fragen, 4 Infotexte.')
            ->assertExitCode(0);
        $this->assertSame(1, AdvancingTemplate::query()->count());

        $bad = tempnam(sys_get_temp_dir(), 'katalog');
        file_put_contents($bad, json_encode($this->catalog([['key' => 'x', 'type' => 'upload', 'label' => 'X']], 'Kaputt')));
        $this->artisan('vc:advancing-catalog', ['action' => 'import', 'file' => $bad])
            ->expectsOutputToContain('upload')
            ->assertExitCode(1);
        unlink($bad);

        $this->artisan('vc:advancing-catalog', ['action' => 'import', 'file' => 'gibt/es/nicht.json'])->assertExitCode(1);
        $this->assertSame(1, AdvancingTemplate::query()->count());
    }

    public function test_modul_advancing_ist_auf_ipa_aus(): void
    {
        // Ohne gespeicherte Module ist alles frei
        $this->assertTrue(Module::Advancing->isEnabled());

        // ipa hat seine Module gespeichert – das neue Modul ist dort nicht dabei
        Setting::put(Setting::MODULES, 'buehnenplan,saalplan,gaesteliste,buchhaltung,preislisten');
        app()->forgetScopedInstances();
        $this->assertFalse(Module::Advancing->isEnabled());
    }

    public function test_schluessel_im_audit_maskiert(): void
    {
        $event = Event::create(['title' => 'Konzert', 'status' => 'bestätigt', 'starts_at' => '2026-12-05 20:00:00']);
        $token = EventAdvancing::newToken();
        EventAdvancing::create([
            'event_id' => $event->id,
            'template_name' => 'Kultur',
            'structure' => [],
            'token' => $token,
            'token_hash' => EventAdvancing::hashToken($token),
        ]);

        $log = AuditLog::query()->where('subject', 'event_advancings')->firstOrFail();
        $this->assertSame('***', $log->new_values['token']);
        $this->assertSame('***', $log->new_values['token_hash']);
        $this->assertStringNotContainsString($token, json_encode($log->new_values));
        $this->assertSame('Advancing', \App\Support\AuditPresenter::SUBJECTS['event_advancings']);
        $this->assertSame('Advancing-Katalog', \App\Support\AuditPresenter::SUBJECTS['advancing_templates']);
    }
}
