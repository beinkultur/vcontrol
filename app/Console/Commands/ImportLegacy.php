<?php

namespace App\Console\Commands;

use App\Models\Damage;
use App\Models\EventFile;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Übernimmt die Daten der PHP-Version (vc.bein.ws). Beliebig oft wiederholbar:
 * Datensätze werden über ihre ID abgeglichen, was es in der Quelle nicht mehr
 * gibt, wird entfernt. Bis zum Umstieg ist die PHP-Version führend.
 */
class ImportLegacy extends Command
{
    use ConfirmableTrait;

    /** @var array<int, string>|null */
    private ?array $userNames = null;

    protected $signature = 'vc:import {--force : ohne Rückfrage, auch in Produktion}';

    protected $description = 'Übernimmt Stammdaten, Rollen mit Rechten, Benutzer und Veranstalter aus der PHP-Version';

    public function handle(): int
    {
        // Nach dem Go-live kommen die Daten aus AppSheet: dann VC_IMPORT_LOCKED=true
        if (config('venuecontrol.import_locked')) {
            $this->error('vc:import ist gesperrt (VC_IMPORT_LOCKED) – die Daten kommen nicht mehr aus der PHP-Version.');

            return self::FAILURE;
        }
        // In Produktion nur mit --force: überschreibt alles, was hier eingegeben wurde
        if (!$this->confirmToProceed('vc:import überschreibt alle Daten mit dem Stand der PHP-Version')) {
            return self::FAILURE;
        }

        $legacy = $this->legacyConnection();
        if ($legacy === null) {
            return self::FAILURE;
        }

        $this->info('Quelle: ' . $legacy->getDatabaseName());
        DB::transaction(function () use ($legacy): void {
            // Belegungen zuerst leeren: Sie verhindern sonst das Entfernen von Räumen,
            // die es in der Quelle nicht mehr gibt. Sie werden unten neu übernommen.
            DB::table('event_room')->delete();
            $this->importCalendars($legacy);
            $this->importSettings($legacy);
            // Vor den Benutzern: users.employee_id und users.trade_id verweisen darauf
            $this->importEmployees($legacy);
            $this->importTrades($legacy);
            $this->importRooms($legacy);
            $this->importFieldOptions($legacy);
            $this->importInventory($legacy);
            $this->importRoles($legacy);
            $this->importUsers($legacy);
            $this->importPromoters($legacy);
            $this->importEvents($legacy);
            $this->importEventDetails($legacy);
            $this->importEventLists($legacy);
            $this->importFiles($legacy);
            $this->importOperations($legacy);
            $this->importDamages($legacy);
            $this->importAccessCodes($legacy);
        });
        // Der Import schreibt an Eloquent vorbei – ein Eintrag für den ganzen Lauf
        Audit::record('import', Audit::IMPORTED, null, ['quelle' => 'PHP-Version'], label: 'Import aus der PHP-Version');

        return self::SUCCESS;
    }

    private function legacyConnection(): ?Connection
    {
        $path = (string) config('venuecontrol.legacy_config');
        if ($path === '' || !is_file($path)) {
            $this->error('VC_LEGACY_CONFIG zeigt nicht auf die config.php der PHP-Version: „' . $path . '“');

            return null;
        }

        $db = (require $path)['db'] ?? null;
        if (!is_array($db) || empty($db['name'])) {
            $this->error('Keine db-Einstellungen in ' . $path);

            return null;
        }

        config(['database.connections.legacy' => [
            'driver' => 'mysql',
            'host' => $db['host'] ?? 'localhost',
            'port' => 3306,
            'database' => $db['name'],
            'username' => $db['user'] ?? '',
            'password' => $db['pass'] ?? '',
            'charset' => $db['charset'] ?? 'utf8mb4',
            'collation' => null,
            'prefix' => '',
            'strict' => true,
        ]]);

        return DB::connection('legacy');
    }

    private function importCalendars(Connection $legacy): void
    {
        $rows = $legacy->table('vc_calendars')->orderBy('sort_order')->get()->map(fn (object $c): array => [
            'key' => $c->key,
            'name' => $c->name,
            'color' => $c->color,
            'freitermin_status' => $c->freitermin_status,
            'is_system' => (bool) $c->is_system,
            'sort_order' => (int) $c->sort_order,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ])->all();

        $this->sync('calendars', $rows, 'key');
    }

    private function importSettings(Connection $legacy): void
    {
        $rows = $legacy->table('vc_settings')->get()->map(fn (object $s): array => [
            'key' => $s->key,
            'value' => $s->value,
            'created_at' => $s->updated_at,
            'updated_at' => $s->updated_at,
        ])->all();

        // Nur übernehmen, nicht spiegeln: Einstellungen, die es nur hier gibt
        // (Kalender-Feed, Bühnen-Stammdaten), darf der Import nicht löschen.
        DB::table('settings')->upsert($rows, ['key']);
        $this->line(sprintf('  %-20s %4d übernommen', 'settings', count($rows)));
    }

    private function importEmployees(Connection $legacy): void
    {
        $rows = $legacy->table('vc_employees')->orderBy('id')->get()->map(fn (object $e): array => [
            'id' => $e->id,
            'first_name' => $e->first_name,
            'last_name' => $e->last_name,
            'initials' => $e->initials,
            'phone' => $e->phone,
            'email' => $e->email,
            'positions' => $this->jsonList($e->positions),
            'created_at' => $e->created_at,
            'updated_at' => $e->updated_at,
        ])->all();

        $this->sync('employees', $rows);
    }

    private function importTrades(Connection $legacy): void
    {
        $rows = $legacy->table('vc_trades')->orderBy('id')->get()->map(fn (object $t): array => [
            'id' => $t->id,
            'short_name' => $t->short_name,
            'name' => $t->name,
            'categories' => $this->jsonList($t->category),
            'email' => $t->email,
            'phone' => $t->phone,
            'address1' => $t->address1,
            'address2' => $t->address2,
            'zip' => $t->zip,
            'city' => $t->city,
            'is_archived' => (bool) $t->is_archived,
            'created_at' => $t->created_at,
            'updated_at' => $t->updated_at,
        ])->all();

        $this->sync('trades', $rows);
    }

    private function importRooms(Connection $legacy): void
    {
        $rows = $legacy->table('vc_rooms')->orderBy('id')->get()->map(fn (object $r): array => [
            'id' => $r->id,
            'name' => $r->name,
            'sort_order' => (int) $r->sort_order,
            'is_active' => (bool) $r->active,
            'created_at' => $r->created_at,
            'updated_at' => $r->created_at,
        ])->all();

        $this->sync('rooms', $rows);
    }

    private function importFieldOptions(Connection $legacy): void
    {
        $rows = $legacy->table('vc_field_options')->orderBy('id')->get()->map(fn (object $o): array => [
            'id' => $o->id,
            'field_key' => $o->field_key,
            'value' => $o->value,
            'parent_value' => trim((string) $o->parent_value) === '' ? null : $o->parent_value,
            'sort_order' => (int) $o->sort_order,
            'is_active' => (bool) $o->active,
            'created_at' => $o->created_at,
            'updated_at' => $o->created_at,
        ])->all();

        $this->sync('field_options', $rows);
    }

    private function importInventory(Connection $legacy): void
    {
        $categories = $legacy->table('vc_inventory_categories')->orderBy('id')->get()->map(fn (object $c): array => [
            'id' => $c->id,
            'name' => $c->name,
            'sort_order' => (int) $c->sort_order,
            'is_active' => (bool) $c->active,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ])->all();
        $items = $legacy->table('vc_inventory_items')->orderBy('id')->get()->map(fn (object $i): array => [
            'id' => $i->id,
            'category_id' => $i->category_id,
            'name' => $i->name,
            'is_active' => (bool) $i->active,
            'created_at' => $i->created_at,
            'updated_at' => $i->updated_at,
        ])->all();

        // Verschwundene Artikel zuerst entfernen, sonst blockiert der Fremdschlüssel
        // das Entfernen ihrer Kategorie
        $this->sync('inventory_items', $items, upsert: false);
        $this->sync('inventory_categories', $categories);
        $this->sync('inventory_items', $items);
    }

    /**
     * Event-Kern. Nicht übernommen: legacy_key, calendar_id (IDs eines
     * Google-Kalenders aus AppSheet) und pl (Projektleitung, im Bestand leer).
     */
    private function importEvents(Connection $legacy): void
    {
        $promoterIds = DB::table('promoters')->pluck('id')->flip();

        $rows = $legacy->table('vc_events')->orderBy('id')->get()->map(fn (object $e): array => [
            'id' => $e->id,
            'va_nr' => $e->va_nr,
            'va_id' => $e->va_id,
            'title' => $e->title,
            'promoter_id' => isset($promoterIds[$e->promoter_id]) ? $e->promoter_id : null,
            'status' => $e->status,
            'event_type1' => $e->event_type1,
            'event_type2' => $e->event_type2,
            'starts_at' => $e->starts_at,
            'ends_at' => $e->ends_at,
            'pax_expected' => $e->pax_expected,
            'pax' => $e->pax,
            'areas' => $this->jsonList($e->areas),
            'seating' => $this->jsonList($e->seating),
            'ticketing' => $e->ticketing,
            'wlan' => $e->wlan,
            'wlan_password' => $e->wlan_password,
            'description' => $e->description,
            'booking_notes' => $e->booking_notes,
            'onsite_contact' => $e->onsite_contact,
            'closed' => (bool) $e->closed,
            'created_at' => $e->created_at,
            'updated_at' => $e->updated_at,
        ])->all();

        $this->sync('events', $rows);
    }

    /** 1:1-Zusatzdaten der Events; übernommen wird nur, was zu einem Event gehört. */
    private function importEventDetails(Connection $legacy): void
    {
        $eventIds = DB::table('events')->pluck('id')->flip();
        $rows = fn (string $table): array => $legacy->table($table)->get()
            ->filter(fn (object $row): bool => isset($eventIds[$row->event_id]))
            ->values()->all();

        $this->sync('event_finances', array_map(fn (object $f): array => [
            'event_id' => $f->event_id,
            'contract_status' => $f->contract_status,
            'accounting_status' => $this->jsonList($f->accounting_status),
            'price_list' => $f->price_list,
            'rent' => $f->rent,
            'invoice_numbers' => $this->jsonValues([$f->invoice_no1, $f->invoice_no2, $f->invoice_no3]),
            'accounting_closed' => (bool) $f->accounting_closed,
        ], $rows('vc_event_finance')), 'event_id');

        $this->sync('event_schedules', array_map(
            fn (object $s): array => $this->columns($s, ['event_id', 'get_in', 'load_in', 'admission', 'vip_admission', 'start_time', 'end_time', 'curfew', 'load_out']),
            $rows('vc_event_schedules'),
        ), 'event_id');

        $this->sync('event_pr', array_map(
            fn (object $p): array => $this->columns($p, ['event_id', 'pr_date', 'pr_status']),
            $rows('vc_event_pr'),
        ), 'event_id');

        $this->sync('event_operations', array_map(
            fn (object $o): array => $this->columns($o, ['event_id', 'power_start_ref', 'power_end_ref', 'power_consumption', 'backstages', 'offices', 'bus_power', 'house_delay']),
            $rows('vc_event_operations'),
        ), 'event_id');

        $this->sync('event_stages', array_map(
            fn (object $s): array => $this->columns($s, ['event_id', 'stage_info', 'width', 'depth', 'height', 'wing_sl_width', 'wing_sl_depth', 'wing_sr_width', 'wing_sr_depth', 'wing_sl_offset', 'wing_sr_offset', 'extra_platforms', 'rollpodest_width', 'rollpodest_depth', 'podest_total', 'stair_third', 'stair_sl_offset', 'stair_sr_offset', 'backwall_cm', 'other_info', 'stage_notes', 'notes', 'sold_out_award']),
            $rows('vc_event_stage'),
        ), 'event_id');

        $this->sync('event_checklists', array_map(
            fn (object $c): array => $this->columns($c, ['event_id', 'hands', 'traffic', 'pvc_setup', 'pvc_teardown', 'cleaning', 'interim_cleaning', 'bar_setup', 'bar_teardown', 'chairs_ordered', 'merch_fee', 'merch_fee_check', 'special_cleaning', 'power_ant', 'house_rig_early', 'briefing_complete']),
            $rows('vc_event_checklist'),
        ), 'event_id');
    }

    /** Listen am Event: Leistungen, Leistungsgruppen, Rollen, Räume, Eingangsrechnungen. */
    private function importEventLists(Connection $legacy): void
    {
        $eventIds = DB::table('events')->pluck('id')->flip();
        $inEvent = fn (object $row): bool => isset($eventIds[$row->event_id]);

        // Leistungen: nur Zeilen mit Inhalt. Die PHP-Version legt je Event alle 19
        // Leistungen an, die meisten bleiben leer.
        $active = [];
        foreach ($legacy->table('vc_event_trade_active')->get()->filter($inEvent) as $a) {
            $active[$a->event_id][$a->service_code] = (bool) $a->active;
        }
        $tradeIds = DB::table('trades')->pluck('id')->flip();
        $services = [];
        foreach ($legacy->table('vc_event_trades')->orderBy('id')->get()->filter($inEvent) as $s) {
            $isActive = $active[$s->event_id][$s->service_code] ?? null;
            $provider = trim((string) $s->provider_label);
            $note = trim((string) $s->note);
            if (!$s->trade_id && $provider === '' && !$s->responsible && $note === '' && $isActive === null) {
                continue;
            }
            $services[] = [
                'id' => $s->id,
                'event_id' => $s->event_id,
                'service' => $s->service_code,
                'trade_id' => isset($tradeIds[$s->trade_id]) ? $s->trade_id : null,
                'provider_label' => $provider === '' ? null : $provider,
                'responsible' => $s->responsible,
                'is_active' => $isActive,
                'note' => $note === '' ? null : $note,
            ];
        }
        $this->sync('event_services', $services);

        $this->replace('event_service_groups', $legacy->table('vc_event_trade_groups')->get()->filter($inEvent)
            ->map(fn (object $g): array => [
                'event_id' => $g->event_id,
                'group_key' => $g->group_key,
                'is_active' => (bool) $g->active,
                'split_setup_teardown' => (bool) $g->split_setup_teardown,
            ])->values()->all());

        // Rollen: nur Zuordnungen, deren Mitarbeiter, Gewerk oder Benutzer existiert
        $known = [
            'employee' => DB::table('employees')->pluck('id')->flip(),
            'trade' => $tradeIds,
            'user' => DB::table('users')->pluck('id')->flip(),
        ];
        $this->replace('event_assignments', $legacy->table('vc_event_role_assignments')->get()->filter($inEvent)
            ->filter(fn (object $a): bool => isset($known[$a->assignee_type][$a->assignee_id]))
            ->map(fn (object $a): array => [
                'event_id' => $a->event_id,
                'role' => $a->role_key,
                'assignee_type' => $a->assignee_type,
                'assignee_id' => $a->assignee_id,
                'starts_at' => $a->starts_at,
                'ends_at' => $a->ends_at,
            ])->values()->all());

        $roomIds = DB::table('rooms')->pluck('id')->flip();
        $this->replace('event_room', $legacy->table('vc_event_rooms')->get()->filter($inEvent)
            ->filter(fn (object $r): bool => isset($roomIds[$r->room_id]))
            ->map(fn (object $r): array => ['event_id' => $r->event_id, 'room_id' => $r->room_id, 'usage_type' => $r->usage_type])
            ->values()->all());

        $userIds = $known['user'];
        $this->sync('event_guests', $legacy->table('vc_event_guests')->orderBy('id')->get()->filter($inEvent)
            ->map(fn (object $g): array => [
                'id' => $g->id,
                'event_id' => $g->event_id,
                'first_name' => $g->first_name,
                'last_name' => $g->last_name,
                'free_tickets' => (int) $g->free_tickets,
                'created_by' => isset($userIds[$g->created_by_user_id]) ? $g->created_by_user_id : null,
                'created_at' => $g->created_at,
                'updated_at' => $g->updated_at,
            ])->values()->all());

        $this->sync('event_notes', $legacy->table('vc_event_notes')->orderBy('id')->get()->filter($inEvent)
            ->map(fn (object $n): array => [
                'id' => $n->id,
                'event_id' => $n->event_id,
                'subject' => $n->subject,
                'body' => $n->body,
                'created_by' => isset($userIds[$n->created_by_user_id]) ? $n->created_by_user_id : null,
                'created_by_name' => $this->author($n->created_by_name, $n->created_by_user_id),
                'updated_by' => isset($userIds[$n->updated_by_user_id]) ? $n->updated_by_user_id : null,
                'updated_by_name' => $this->author($n->updated_by_name, $n->updated_by_user_id),
                'created_at' => $n->created_at,
                'updated_at' => $n->updated_at,
            ])->values()->all());

        $this->replace('event_incoming_invoices', $legacy->table('vc_event_incoming_invoices')->get()->filter($inEvent)
            ->map(fn (object $i): array => [
                'event_id' => $i->event_id,
                'invoice_key' => $i->invoice_key,
                'is_active' => (bool) $i->is_active,
                'is_received' => (bool) $i->is_received,
                'updated_by' => isset($userIds[$i->updated_by_user_id]) ? $i->updated_by_user_id : null,
                'created_at' => $i->updated_at,
                'updated_at' => $i->updated_at,
            ])->values()->all());
    }

    /**
     * Tabelle ohne eigene IDs komplett neu füllen.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    /**
     * Event-Dateien: Datensätze mit IDs wie gehabt, die Dateien selbst werden aus
     * der PHP-Version kopiert (Disk „local“, event-files/import/). Kopien, deren
     * Datensatz es dort nicht mehr gibt, verschwinden wieder.
     */
    private function importFiles(Connection $legacy): void
    {
        $this->sync('event_file_tags', $legacy->table('vc_event_file_tags')->orderBy('id')->get()
            ->map(fn (object $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'sort_order' => (int) $t->sort_order,
                'is_archived' => (bool) $t->is_archived,
                'created_at' => $t->created_at,
                'updated_at' => $t->updated_at,
            ])->values()->all());

        $root = dirname((string) config('venuecontrol.legacy_config'), 2);
        $disk = Storage::disk(EventFile::DISK);
        $eventIds = DB::table('events')->pluck('id')->flip();
        $userIds = DB::table('users')->pluck('id')->flip();
        $rows = [];
        $missing = [];
        foreach ($legacy->table('vc_event_files')->orderBy('id')->get() as $f) {
            if ($f->event_id !== null && !isset($eventIds[$f->event_id])) {
                continue;
            }
            $target = 'event-files/import/' . $f->id . '-' . basename((string) $f->file_path);
            $source = $this->legacyFile($root, (string) $f->file_path);
            if ($source === null) {
                $missing[] = $f->original_name;
            } elseif (!$disk->exists($target) || $disk->size($target) !== filesize($source)) {
                $disk->put($target, fopen($source, 'r'));
            }
            $rows[] = [
                'id' => $f->id,
                'tag_id' => $f->tag_id,
                'event_id' => $f->event_id,
                'title' => $f->title,
                'path' => $target,
                'original_name' => $f->original_name,
                'mime_type' => $f->mime_type,
                'size' => $f->file_size,
                'version' => (int) $f->version,
                'uploaded_at' => $f->uploaded_at,
                'is_shared' => (bool) $f->is_shared,
                'created_by' => isset($userIds[$f->created_by_user_id]) ? $f->created_by_user_id : null,
                'created_by_name' => $this->author($f->created_by_name, $f->created_by_user_id),
                'updated_by' => isset($userIds[$f->updated_by_user_id]) ? $f->updated_by_user_id : null,
                'updated_by_name' => $this->author($f->updated_by_name, $f->updated_by_user_id),
                'created_at' => $f->created_at,
                'updated_at' => $f->updated_at,
            ];
        }
        $this->sync('event_files', $rows);

        $fileIds = DB::table('event_files')->pluck('id')->flip();
        $this->replace('event_file_links', $legacy->table('vc_event_file_links')->get()
            ->filter(fn (object $l): bool => isset($eventIds[$l->event_id], $fileIds[$l->file_id]))
            ->map(fn (object $l): array => [
                'event_id' => $l->event_id,
                'file_id' => $l->file_id,
                'created_by' => isset($userIds[$l->created_by_user_id]) ? $l->created_by_user_id : null,
                'created_by_name' => $l->created_by_name,
                'created_at' => $l->created_at,
            ])->values()->all());

        $kept = array_flip(array_column($rows, 'path'));
        foreach ($disk->files('event-files/import') as $path) {
            if (!isset($kept[$path])) {
                $disk->delete($path);
            }
        }
        if ($missing !== []) {
            $this->warn('  In der PHP-Version nicht gefunden: ' . implode(', ', $missing));
        }
    }

    /** Bestellscheine mit Artikeln und Übergabeprotokolle, samt Unterschriften. */
    private function importOperations(Connection $legacy): void
    {
        $eventIds = DB::table('events')->pluck('id')->flip();
        $userIds = DB::table('users')->pluck('id')->flip();
        $user = fn (mixed $id): mixed => isset($userIds[$id]) ? $id : null;

        $this->sync('article_categories', $legacy->table('vc_article_categories')->orderBy('id')->get()
            ->map(fn (object $c): array => [
                'id' => $c->id, 'name' => $c->name, 'sort_order' => (int) $c->sort_order, 'is_active' => (bool) $c->active,
                'created_at' => $c->created_at, 'updated_at' => $c->updated_at,
            ])->values()->all());
        $this->sync('articles', $legacy->table('vc_articles')->orderBy('id')->get()
            ->map(fn (object $a): array => [
                'id' => $a->id, 'category_id' => $a->category_id, 'name' => $a->name, 'short_name' => $a->short_name,
                'unit' => $a->unit, 'price' => $a->price, 'is_active' => (bool) $a->active,
                'created_at' => $a->created_at, 'updated_at' => $a->updated_at,
            ])->values()->all());

        $this->sync('order_slips', $legacy->table('vc_order_slips')->orderBy('id')->get()
            ->filter(fn (object $s): bool => isset($eventIds[$s->event_id]))
            ->map(fn (object $s): array => [
                'id' => $s->id, 'event_id' => $s->event_id, 'ordered_from' => $s->ordered_from, 'ordered_at' => $s->ordered_at,
                'signature' => $s->signature_data, 'comment' => $s->comment, 'is_settled' => (bool) $s->settled,
                'created_by' => $user($s->created_by_user_id), 'created_by_name' => $this->author($s->created_by_name, $s->created_by_user_id),
                'updated_by' => $user($s->updated_by_user_id), 'updated_by_name' => $this->author($s->updated_by_name, $s->updated_by_user_id),
                'created_at' => $s->created_at, 'updated_at' => $s->updated_at,
            ])->values()->all());
        $slipIds = DB::table('order_slips')->pluck('id')->flip();
        $articleIds = DB::table('articles')->pluck('id')->flip();
        $this->sync('order_slip_items', $legacy->table('vc_order_slip_items')->orderBy('id')->get()
            ->filter(fn (object $i): bool => isset($slipIds[$i->order_slip_id]))
            ->map(fn (object $i): array => [
                'id' => $i->id, 'order_slip_id' => $i->order_slip_id,
                'article_id' => isset($articleIds[$i->article_id]) ? $i->article_id : null,
                'category_id' => $i->category_id, 'category_name' => $i->category_name, 'article_name' => $i->article_name,
                'unit' => $i->unit, 'unit_price' => $i->unit_price, 'quantity' => $i->quantity, 'line_total' => $i->line_total,
                'sort_order' => (int) $i->sort_order,
            ])->values()->all());

        $this->sync('handover_protocols', $legacy->table('vc_handover_protocols')->orderBy('id')->get()
            ->filter(fn (object $p): bool => isset($eventIds[$p->event_id]))
            ->map(fn (object $p): array => [
                'id' => $p->id, 'event_id' => $p->event_id, 'handed_to' => $p->handed_to, 'handed_at' => $p->handed_at,
                'signature' => $p->signature_data, 'status' => $p->status, 'returned_at' => $p->returned_at,
                'returned_by' => $user($p->returned_by_user_id), 'comment' => $p->comment,
                'created_by' => $user($p->created_by_user_id), 'created_by_name' => $this->author($p->created_by_name, $p->created_by_user_id),
                'updated_by' => $user($p->updated_by_user_id), 'updated_by_name' => $this->author($p->updated_by_name, $p->updated_by_user_id),
                'created_at' => $p->created_at, 'updated_at' => $p->updated_at,
            ])->values()->all());
        $protocolIds = DB::table('handover_protocols')->pluck('id')->flip();
        $this->sync('handover_protocol_items', $legacy->table('vc_handover_protocol_items')->orderBy('id')->get()
            ->filter(fn (object $i): bool => isset($protocolIds[$i->protocol_id]))
            ->map(fn (object $i): array => [
                'id' => $i->id, 'protocol_id' => $i->protocol_id, 'inventory_item_id' => $i->inventory_item_id,
                'category_id' => $i->category_id, 'category_name' => $i->category_name, 'item_name' => $i->item_name,
                'sort_order' => (int) $i->sort_order,
            ])->values()->all());
    }

    /**
     * Schäden mit Fotos. Die Fotos werden aus der PHP-Version kopiert (Disk „local“,
     * damages/import/{Schaden}/); Kopien ohne Datensatz verschwinden wieder.
     */
    private function importDamages(Connection $legacy): void
    {
        $root = dirname((string) config('venuecontrol.legacy_config'), 2);
        $disk = Storage::disk(Damage::DISK);
        $eventIds = DB::table('events')->pluck('id')->flip();
        $userIds = DB::table('users')->pluck('id')->flip();
        $user = fn (mixed $id): mixed => isset($userIds[$id]) ? $id : null;
        $photos = $legacy->table('vc_damage_photos')->orderBy('sort_order')->orderBy('id')->get()->groupBy('damage_id');

        $rows = [];
        $kept = [];
        $missing = [];
        foreach ($legacy->table('vc_damages')->orderBy('id')->get() as $d) {
            $paths = [];
            $names = [];
            foreach ($photos->get($d->id, collect()) as $photo) {
                $target = 'damages/import/' . $d->id . '/' . $photo->id . '-' . basename((string) $photo->file_path);
                $source = $this->legacyFile($root, (string) $photo->file_path);
                if ($source === null) {
                    $missing[] = $photo->original_name ?: basename((string) $photo->file_path);

                    continue;
                }
                if (!$disk->exists($target) || $disk->size($target) !== filesize($source)) {
                    $disk->put($target, fopen($source, 'r'));
                }
                $paths[] = $target;
                $names[$target] = $photo->original_name ?: basename((string) $photo->file_path);
                $kept[$target] = true;
            }
            $rows[] = [
                'id' => $d->id,
                'event_id' => isset($eventIds[$d->event_id]) ? $d->event_id : null,
                'recorded_at' => $d->recorded_at,
                'description' => $d->description,
                'is_fixed' => (bool) $d->fixed,
                'photos' => $paths === [] ? null : json_encode($paths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'photo_names' => $names === [] ? null : json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'recorded_by_name' => $d->recorded_by_name,
                'created_by' => $user($d->created_by_user_id),
                'created_by_name' => $this->author($d->created_by_name, $d->created_by_user_id),
                'updated_by' => $user($d->updated_by_user_id),
                'updated_by_name' => $this->author($d->updated_by_name, $d->updated_by_user_id),
                'created_at' => $d->created_at,
                'updated_at' => $d->updated_at,
            ];
        }
        $this->sync('damages', $rows);

        foreach ($disk->allFiles('damages/import') as $path) {
            if (!isset($kept[$path])) {
                $disk->delete($path);
            }
        }
        if ($missing !== []) {
            $this->warn('  Schadensfotos in der PHP-Version nicht gefunden: ' . implode(', ', $missing));
        }
    }

    /** Tageszugangscodes (vc_access_codes), ein Code je Tag. */
    private function importAccessCodes(Connection $legacy): void
    {
        $userIds = DB::table('users')->pluck('id')->flip();
        $this->sync('access_codes', $legacy->table('vc_access_codes')->orderBy('id')->get()
            ->map(fn (object $c): array => [
                'id' => $c->id,
                'code' => $c->code,
                'valid_from' => $c->valid_from,
                'valid_on' => $c->valid_on,
                'created_by' => isset($userIds[$c->created_by_user_id]) ? $c->created_by_user_id : null,
                'created_by_name' => $this->author($c->created_by_name, $c->created_by_user_id),
                'created_at' => $c->created_at,
                'updated_at' => $c->created_at,
            ])->values()->all());
    }

    /** Pfad einer Datei der PHP-Version wie dort FileStorage::resolveFullPath – nur unter den Upload-Ordnern. */
    private function legacyFile(string $root, string $stored): ?string
    {
        $stored = trim($stored);
        $candidate = match (true) {
            str_starts_with($stored, '/uploads/') => $root . '/public' . $stored,
            str_starts_with(ltrim($stored, '/'), 'storage/uploads/') => $root . '/' . ltrim($stored, '/'),
            default => null,
        };
        $real = $candidate === null ? false : realpath($candidate);
        if ($real === false || !is_file($real)) {
            return null;
        }
        foreach (['/public/uploads', '/storage/uploads'] as $dir) {
            $base = realpath($root . $dir);
            if ($base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * Name des Verfassers wie RecordMeta der PHP-Version: der gespeicherte Name,
     * sonst der Name des Benutzerkontos.
     */
    private function author(?string $stored, mixed $userId): ?string
    {
        if (filled($stored)) {
            return $stored;
        }
        $this->userNames ??= DB::table('users')->get(['id', 'first_name', 'last_name', 'email'])
            ->mapWithKeys(fn (object $u): array => [$u->id => trim($u->first_name . ' ' . $u->last_name) ?: $u->email])
            ->all();

        return $this->userNames[$userId] ?? null;
    }

    private function replace(string $table, array $rows): void
    {
        DB::table($table)->delete();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }
        $this->line(sprintf('  %-20s %4d übernommen', $table, count($rows)));
    }

    /**
     * Gleichnamige Spalten unverändert übernehmen.
     *
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function columns(object $row, array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            $out[$column] = $row->{$column} ?? null;
        }

        return $out;
    }

    /**
     * Mehrere Einzelspalten (z. B. invoice_no1–3) als JSON-Liste, leere weggelassen.
     *
     * @param  list<mixed>  $values
     */
    private function jsonValues(array $values): ?string
    {
        $items = array_values(array_filter(array_map(fn (mixed $v): string => trim((string) $v), $values), 'strlen'));

        return $items === [] ? null : json_encode($items, JSON_UNESCAPED_UNICODE);
    }

    /** Komma-Text der PHP-Version („Umbau , Verkehr“) als JSON-Liste. */
    private function jsonList(?string $value): ?string
    {
        $items = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen')));

        return $items === [] ? null : json_encode($items, JSON_UNESCAPED_UNICODE);
    }

    private function importRoles(Connection $legacy): void
    {
        // Die Matrizen liegen in der PHP-Version als Zeilen je Rolle und Bereich
        $permissions = $this->levelsBy($legacy, 'vc_role_permissions', 'role_id', 'area_key');
        $calendars = $this->levelsBy($legacy, 'vc_role_calendar_permissions', 'role_id', 'calendar_key');

        $rows = $legacy->table('vc_roles')->orderBy('id')->get()->map(fn (object $r): array => [
            'id' => $r->id,
            'slug' => $r->slug,
            'name' => $r->name,
            'description' => $r->description,
            'is_system' => (bool) $r->is_system,
            'is_super' => (bool) $r->is_super,
            'sort_order' => (int) $r->sort_order,
            'permissions' => json_encode((object) ($permissions[$r->id] ?? [])),
            'calendar_permissions' => json_encode((object) ($calendars[$r->id] ?? [])),
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ])->all();

        $this->sync('roles', $rows);
    }

    /**
     * Zeilen „Besitzer, Schlüssel, Stufe“ als Matrix je Besitzer.
     *
     * @return array<int, array<string, string>>
     */
    private function levelsBy(Connection $legacy, string $table, string $ownerColumn, string $keyColumn): array
    {
        $matrix = [];
        foreach ($legacy->table($table)->get() as $row) {
            $matrix[(int) $row->{$ownerColumn}][(string) $row->{$keyColumn}] = (string) $row->access_level;
        }

        return $matrix;
    }

    private function importUsers(Connection $legacy): void
    {
        $calendars = $this->levelsBy($legacy, 'vc_user_calendar_permissions', 'user_id', 'calendar_key');

        $rows = $legacy->table('vc_users')->orderBy('id')->get()->map(fn (object $u): array => [
            'id' => $u->id,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'email' => $u->email,
            // bcrypt aus password_hash() – Laravel prüft den Hash unverändert
            'password' => $u->password_hash,
            'account_type' => $u->account_type,
            'employee_id' => $u->employee_id,
            'trade_id' => $u->trade_id,
            'is_active' => (bool) $u->is_active,
            'calendar_permissions' => json_encode((object) ($calendars[$u->id] ?? [])),
            'created_at' => $u->created_at,
            'updated_at' => $u->updated_at,
        ])->all();

        $this->sync('users', $rows);

        $roleIds = DB::table('roles')->pluck('id', 'slug');
        $pairs = [];
        foreach ($legacy->table('vc_user_roles')->get() as $assignment) {
            if (!isset($roleIds[$assignment->role])) {
                $this->warn("  Rolle „{$assignment->role}“ von Benutzer #{$assignment->user_id} gibt es nicht – übersprungen");
                continue;
            }
            $pairs[] = ['role_id' => $roleIds[$assignment->role], 'user_id' => $assignment->user_id];
        }
        DB::table('role_user')->delete();
        DB::table('role_user')->insert($pairs);
        $this->line(sprintf('  %-20s %4d übernommen', 'role_user', count($pairs)));
    }

    private function importPromoters(Connection $legacy): void
    {
        $promoters = [];
        $contacts = [];
        foreach ($legacy->table('vc_promoters')->orderBy('id')->get() as $p) {
            $promoters[] = [
                'id' => $p->id,
                'short_name' => $p->short_name,
                'name' => $p->name,
                'customer_no' => $p->customer_no,
                'email' => $p->email,
                'address1' => $p->address1,
                'address2' => $p->address2,
                'zip' => $p->zip,
                'city' => $p->city,
                'logo_path' => $p->logo_path,
                'is_archived' => (bool) $p->is_archived,
                'created_at' => $p->created_at,
                'updated_at' => $p->updated_at,
            ];

            // Aus den vier festen Spaltengruppen werden einzelne Ansprechpartner
            for ($i = 1; $i <= 4; $i++) {
                $contact = [];
                foreach (['first_name', 'last_name', 'role', 'phone', 'email'] as $field) {
                    $value = trim((string) ($p->{"contact{$i}_{$field}"} ?? ''));
                    $contact[$field] = $value === '' ? null : $value;
                }
                if (array_filter($contact) === []) {
                    continue;
                }
                $contacts[] = $contact + [
                    'promoter_id' => $p->id,
                    'sort_order' => $i,
                    'created_at' => $p->created_at,
                    'updated_at' => $p->updated_at,
                ];
            }
        }

        $this->sync('promoters', $promoters);
        DB::table('promoter_contacts')->delete();
        foreach (array_chunk($contacts, 500) as $chunk) {
            DB::table('promoter_contacts')->insert($chunk);
        }
        $this->line(sprintf('  %-20s %4d übernommen', 'promoter_contacts', count($contacts)));
    }

    /**
     * Spiegelt die Quellzeilen in die Tabelle: anlegen oder aktualisieren über
     * den Schlüssel, Zeilen ohne Gegenstück in der Quelle entfernen.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function sync(string $table, array $rows, string $key = 'id', bool $upsert = true): void
    {
        if ($upsert) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->upsert($chunk, [$key]);
            }
        }
        $keys = array_column($rows, $key);
        $removed = DB::table($table)->whereNotIn($key, $keys === [] ? ['__keiner__'] : $keys)->delete();

        if ($upsert) {
            $this->line(sprintf('  %-20s %4d übernommen%s', $table, count($rows), $removed > 0 ? ", {$removed} entfernt" : ''));
        }
    }
}
