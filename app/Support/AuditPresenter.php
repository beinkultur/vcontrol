<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Enums\AssignmentRole;
use App\Enums\Responsible;
use App\Enums\RoomUsage;
use App\Enums\ServiceCode;
use App\Filament\Resources\Events\Schemas\EventForm;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EventFile;
use App\Models\EventFileTag;
use App\Models\EventIncomingInvoice;
use App\Models\InventoryItem;
use App\Models\Promoter;
use App\Models\Role;
use App\Models\Room;
use App\Models\Trade;
use App\Models\User;
use ArrayObject;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Lesbare Darstellung des Änderungsprotokolls: deutsche Bereichs- und
 * Feldnamen, Namen statt IDs, ja/nein statt 1/0.
 */
final class AuditPresenter
{
    public const SUBJECTS = [
        'events' => 'Event',
        'event_finances' => 'Buchhaltung',
        'event_pr' => 'PR',
        'event_schedules' => 'Zeiten',
        'event_checklists' => 'Checkliste',
        'event_stages' => 'Bühne',
        'event_operations' => 'Betrieb',
        'event_assignments' => 'Personal',
        'event_services' => 'Gewerke',
        'event_service_groups' => 'Leistungsgruppe',
        'event_incoming_invoices' => 'Eingangsrechnung',
        'event_room' => 'Raumbelegung',
        'event_guests' => 'Gast',
        'event_notes' => 'Notiz',
        'event_files' => 'Datei',
        'event_file_links' => 'Datei-Verknüpfung',
        'event_file_tags' => 'Datei-Tag',
        'order_slips' => 'Bestellschein',
        'order_slip_items' => 'Bestellposition',
        'handover_protocols' => 'Übergabeprotokoll',
        'handover_protocol_items' => 'Übergabe-Position',
        'damages' => 'Schaden',
        'event_show_checklists' => 'Durchführungs-Checkliste',
        'access_codes' => 'Zugangscode',
        'users' => 'Benutzer',
        'roles' => 'Rolle',
        'role_user' => 'Rollen-Zuordnung',
        'promoters' => 'Veranstalter',
        'promoter_contacts' => 'Ansprechpartner',
        'employees' => 'Mitarbeiter',
        'trades' => 'Gewerk',
        'rooms' => 'Raum',
        'field_options' => 'Feldoption',
        'inventory_categories' => 'Inventar-Kategorie',
        'inventory_items' => 'Inventar',
        'articles' => 'Artikel',
        'article_categories' => 'Artikel-Kategorie',
        'calendars' => 'Kalender-Ebene',
        'settings' => 'Einstellung',
        'import' => 'Import',
    ];

    public const ACTIONS = [
        Audit::CREATED => 'Angelegt',
        Audit::UPDATED => 'Geändert',
        Audit::DELETED => 'Gelöscht',
        Audit::IMPORTED => 'Importiert',
    ];

    /** Feldnamen, gleich in allen Tabellen */
    private const FIELDS = [
        'title' => 'Titel', 'name' => 'Name', 'short_name' => 'Kürzel', 'first_name' => 'Vorname', 'last_name' => 'Nachname',
        'email' => 'E-Mail', 'phone' => 'Telefon', 'address1' => 'Adresse', 'address2' => 'Adresszusatz', 'zip' => 'PLZ', 'city' => 'Ort',
        'description' => 'Beschreibung', 'note' => 'Notiz', 'comment' => 'Anmerkungen', 'remarks' => 'Bemerkungen',
        'sort_order' => 'Reihenfolge', 'is_active' => 'Aktiv', 'is_archived' => 'Archiviert', 'is_system' => 'System',
        'event_id' => 'Event', 'promoter_id' => 'Veranstalter', 'trade_id' => 'Gewerk', 'employee_id' => 'Mitarbeiter',
        'user_id' => 'Benutzer', 'role_id' => 'Rolle', 'room_id' => 'Raum', 'tag_id' => 'Tag', 'file_id' => 'Datei',
        'article_id' => 'Artikel', 'category_id' => 'Kategorie', 'inventory_item_id' => 'Inventar', 'returned_by' => 'Zurückgenommen von',
        // Event
        'va_nr' => 'VA-Nr.', 'va_id' => 'VA-ID', 'status' => 'Status', 'event_type1' => 'VA-Kategorie 1', 'event_type2' => 'VA-Kategorie 2',
        'starts_at' => 'Beginn', 'ends_at' => 'Ende', 'pax_expected' => 'PAX erwartet', 'pax' => 'PAX abgerechnet', 'areas' => 'Bereiche',
        'seating' => 'Bestuhlung', 'ticketing' => 'Ticketing', 'wlan' => 'WLAN', 'wlan_password' => 'WLAN-Passwort',
        'booking_notes' => 'Notizen Buchung', 'onsite_contact' => 'Ansprechpartner vor Ort', 'closed' => 'Event abgeschlossen',
        // Buchhaltung, PR
        'contract_status' => 'Vertragsstatus', 'accounting_status' => 'FIBU-Status', 'price_list' => 'Preisliste', 'rent' => 'Miete',
        'invoice_numbers' => 'Rechnungsnummern', 'accounting_closed' => 'Abrechnung abgeschlossen', 'pr_date' => 'PR-Datum', 'pr_status' => 'PR-Status',
        // Zeiten
        'get_in' => 'Get-in', 'load_in' => 'Load-in', 'admission' => 'Einlass', 'vip_admission' => 'VIP-Einlass', 'start_time' => 'Show-Beginn',
        'end_time' => 'Show-Ende', 'curfew' => 'Curfew', 'load_out' => 'Load-out',
        // Checkliste
        'merch_fee' => 'Merch-Fee', 'special_cleaning' => 'Sonderreinigung', 'power_ant' => 'Elektro-Ameise', 'house_rig_early' => 'Haus-Rig ab 7 Uhr',
        'briefing_complete' => 'Briefing vollständig',
        // Bühne
        'stage_info' => 'Bühne (Info)', 'width' => 'Breite', 'depth' => 'Tiefe', 'height' => 'Höhe', 'wing_sl_width' => 'Wing SL Breite',
        'wing_sl_depth' => 'Wing SL Tiefe', 'wing_sr_width' => 'Wing SR Breite', 'wing_sr_depth' => 'Wing SR Tiefe', 'wing_sl_offset' => 'Wing SL Versatz',
        'wing_sr_offset' => 'Wing SR Versatz', 'extra_platforms' => 'Sonstige Podeste', 'rollpodest_width' => 'Rollipodest Breite',
        'rollpodest_depth' => 'Rollipodest Tiefe', 'podest_total' => 'Podeste gesamt', 'stair_third' => 'Dritte Treppe',
        'stair_sl_offset' => 'Treppe SL Versatz', 'stair_sr_offset' => 'Treppe SR Versatz', 'backwall_cm' => 'Abstand Rückwand (cm)',
        'other_info' => 'Sonstige Podeste (AppSheet)', 'stage_notes' => 'Anmerkungen Bühne', 'notes' => 'Anmerkungen (AppSheet)',
        'sold_out_award' => 'Sold-Out-Award',
        // Betrieb
        'power_start_ref' => 'Strom Referenz Anfang', 'power_end_ref' => 'Strom Referenz Ende', 'power_meter_start' => 'Stromzähler Anfang',
        'power_meter_end' => 'Stromzähler Ende', 'power_consumption' => 'Stromverbrauch', 'backstages' => 'Backstages', 'offices' => 'Büros',
        'bus_power' => 'Bus-Strom', 'house_delay' => 'Haus-Delay',
        // Personal, Gewerke, Räume, Rechnungen
        'role' => 'Rolle', 'assignee_type' => 'Art', 'assignee_id' => 'Person', 'service' => 'Leistung', 'provider_label' => 'Anbieter',
        'responsible' => 'Verantwortlich', 'group_key' => 'Leistungsgruppe', 'split_setup_teardown' => 'Auf-/Abbau getrennt',
        'usage_type' => 'Nutzung', 'invoice_key' => 'Rechnung', 'is_received' => 'Eingegangen',
        // Gäste, Notizen, Dateien
        'free_tickets' => 'Freikarten', 'subject' => 'Betreff', 'body' => 'Text', 'path' => 'Datei', 'original_name' => 'Dateiname',
        'mime_type' => 'Typ', 'size' => 'Größe', 'version' => 'Version', 'uploaded_at' => 'Hochgeladen', 'is_shared' => 'Übergreifend',
        // Bestellscheine, Übergaben, Schäden, Checklisten
        'ordered_from' => 'Bestellt von', 'ordered_at' => 'Bestellt am', 'signature' => 'Unterschrift', 'is_settled' => 'Abgerechnet',
        'order_slip_id' => 'Bestellschein', 'article_name' => 'Artikel', 'category_name' => 'Kategorie', 'unit' => 'Einheit',
        'unit_price' => 'Preis', 'quantity' => 'Menge', 'line_total' => 'Summe', 'handed_to' => 'Übergeben an', 'handed_at' => 'Übergeben am',
        'returned_at' => 'Zurück am', 'protocol_id' => 'Protokoll', 'item_name' => 'Gegenstand', 'recorded_at' => 'Zeitpunkt',
        'is_fixed' => 'Behoben', 'photos' => 'Fotos', 'photo_names' => 'Foto-Namen', 'recorded_by_name' => 'Aufgenommen durch',
        'checked_at' => 'Zeitpunkt', 'house_rep' => 'House-Rep.', 'house_rep_signature' => 'Unterschrift House-Rep.',
        'promoter_rep' => 'Prom.-Rep.', 'promoter_rep_signature' => 'Unterschrift Prom.-Rep.', 'checks' => 'Prüfpunkte',
        // Codes, Benutzer, Rollen, Stammdaten
        'code' => 'Code', 'valid_from' => 'Gültig ab', 'valid_on' => 'Gültig am', 'email_verified_at' => 'E-Mail bestätigt',
        'password' => 'Passwort', 'account_type' => 'Kontotyp', 'calendar_permissions' => 'Kalender-Rechte', 'slug' => 'Kürzel',
        'is_super' => 'Admin', 'permissions' => 'Rechte', 'customer_no' => 'Kundennummer', 'logo_path' => 'Logo', 'initials' => 'Kürzel',
        'positions' => 'Positionen', 'categories' => 'Leistungsbereiche', 'field_key' => 'Feld', 'value' => 'Wert',
        'parent_value' => 'Übergeordnet', 'price' => 'Preis', 'key' => 'Schlüssel', 'color' => 'Farbe', 'freitermin_status' => 'Freitermin-Status',
    ];

    /** Abweichende Feldnamen je Tabelle */
    private const SUBJECT_FIELDS = [
        'events' => ['status' => 'VA-Status', 'starts_at' => 'Beginn', 'ends_at' => 'Ende'],
        'event_assignments' => ['starts_at' => 'von', 'ends_at' => 'bis'],
        'event_incoming_invoices' => ['is_active' => 'Erwartet'],
        'event_stages' => ['width' => 'Bühne Breite', 'depth' => 'Bühne Tiefe', 'height' => 'Bühnenhöhe'],
        'settings' => ['key' => 'Einstellung'],
        'promoter_contacts' => ['role' => 'Funktion'],
    ];

    /** Felder mit ja/nein */
    private const BOOLEANS = ['closed', 'accounting_closed', 'power_ant', 'house_rig_early', 'briefing_complete', 'sold_out_award', 'house_delay',
        'stair_third', 'split_setup_teardown', 'is_active', 'is_archived', 'is_system', 'is_super', 'is_shared', 'is_settled', 'is_fixed',
        'is_received'];

    /** Fremdschlüssel → [Modell, Spalte(n) für den Namen] */
    private const REFERENCES = [
        'promoter_id' => [Promoter::class, 'name'],
        'trade_id' => [Trade::class, 'name'],
        'employee_id' => [Employee::class, ['first_name', 'last_name']],
        'user_id' => [User::class, ['first_name', 'last_name']],
        'returned_by' => [User::class, ['first_name', 'last_name']],
        'role_id' => [Role::class, 'name'],
        'room_id' => [Room::class, 'name'],
        'tag_id' => [EventFileTag::class, 'name'],
        'file_id' => [EventFile::class, 'title'],
        'article_id' => [Article::class, 'name'],
        'inventory_item_id' => [InventoryItem::class, 'name'],
    ];

    public static function subject(?string $subject): string
    {
        return self::SUBJECTS[$subject] ?? (string) $subject;
    }

    public static function action(?string $action): string
    {
        return self::ACTIONS[$action] ?? (string) $action;
    }

    public static function actionColor(?string $action): string
    {
        return match ($action) {
            Audit::CREATED => 'success',
            Audit::DELETED => 'danger',
            Audit::IMPORTED => 'gray',
            default => 'info',
        };
    }

    public static function field(string $subject, string $field): string
    {
        // Prüfpunkte der Durchführungs-Checkliste: checks.emergency_case.value
        if (str_starts_with($field, 'checks.')) {
            $parts = explode('.', $field);
            $label = ShowChecklist::labels()[$parts[1] ?? ''] ?? ($parts[1] ?? '');

            return 'Prüfpunkt ' . $label . (($parts[2] ?? '') === 'note' ? ' (Anmerkung)' : '');
        }
        if (str_contains($field, '.')) {
            [$head, $rest] = explode('.', $field, 2);

            return self::field($subject, $head) . ' › ' . $rest;
        }
        if ($subject === 'event_checklists' && isset(EventForm::CHECKS[$field])) {
            return EventForm::CHECKS[$field];
        }

        return self::SUBJECT_FIELDS[$subject][$field] ?? self::FIELDS[$field] ?? $field;
    }

    /** Wert lesbar: Namen statt IDs, ja/nein, deutsche Daten. */
    public static function value(string $field, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '–';
        }
        if (is_array($value)) {
            return array_is_list($value)
                ? implode(', ', array_map(fn (mixed $item): string => is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : (string) $item, $value))
                : implode(', ', array_map(fn (string $key, mixed $item): string => $key . ': ' . (is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : (string) $item), array_keys($value), $value));
        }
        $field = Str::afterLast($field, '.');
        if (in_array($field, self::BOOLEANS, true) && in_array((string) $value, ['0', '1', 'true', 'false'], true)) {
            return in_array((string) $value, ['1', 'true'], true) ? 'ja' : 'nein';
        }
        if (isset(self::REFERENCES[$field]) && is_numeric($value)) {
            return self::name($field, (int) $value) ?? '#' . $value;
        }
        $label = match ($field) {
            'role' => AssignmentRole::tryFrom((string) $value)?->getLabel(),
            'usage_type' => RoomUsage::tryFrom((string) $value)?->getLabel(),
            'responsible' => Responsible::tryFrom((string) $value)?->getLabel(),
            'service' => ServiceCode::tryFrom((string) $value)?->getLabel(),
            'account_type' => AccountType::tryFrom((string) $value)?->getLabel(),
            'assignee_type' => ['employee' => 'Mitarbeiter', 'trade' => 'Gewerk', 'user' => 'Benutzer'][(string) $value] ?? null,
            'invoice_key' => EventIncomingInvoice::SLOTS[(string) $value] ?? null,
            default => null,
        };
        if ($label !== null) {
            return $label;
        }
        $text = (string) $value;
        if (in_array($text, ['yes', 'no', 'na'], true)) {
            return ['yes' => 'ja', 'no' => 'nein', 'na' => 'entfällt'][$text];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $text) === 1) {
            $date = Carbon::parse($text);

            return strlen($text) === 10 || $date->format('H:i:s') === '00:00:00' && !str_contains($field, '_at')
                ? $date->format('d.m.Y')
                : $date->format('d.m.Y H:i');
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $text) === 1) {
            return substr($text, 0, 5);
        }

        return $text;
    }

    /**
     * Zeilen für die Detailansicht: Feld, vorher, nachher. Verschachtelte Werte
     * (Rechte, Prüfpunkte) werden einzeln verglichen.
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    public static function changes(AuditLog $log): array
    {
        $old = self::flatten($log->old_values ?? []);
        $new = self::flatten($log->new_values ?? []);
        $rows = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $before = $old[$key] ?? null;
            $after = $new[$key] ?? null;
            if ($log->action === Audit::UPDATED && $before === $after) {
                continue;
            }
            $rows[] = [
                'field' => self::field($log->subject, (string) $key),
                'old' => self::value((string) $key, $before),
                'new' => self::value((string) $key, $after),
            ];
        }

        return $rows;
    }

    /** Eine Zeile für die Liste, z. B. „Miete: 1.000 → 1.200; Preisliste: A → B“ */
    public static function summary(AuditLog $log): string
    {
        $rows = self::changes($log);
        if ($log->action === Audit::IMPORTED) {
            return 'Datenübernahme' . (isset($log->new_values['quelle']) ? ' aus ' . $log->new_values['quelle'] : '');
        }
        $shown = array_slice($rows, 0, 3);
        $parts = array_map(fn (array $row): string => match ($log->action) {
            Audit::UPDATED => "{$row['field']}: " . Str::limit($row['old'], 40) . ' → ' . Str::limit($row['new'], 40),
            Audit::DELETED => "{$row['field']}: " . Str::limit($row['old'], 40),
            default => "{$row['field']}: " . Str::limit($row['new'], 40),
        }, $shown);
        $more = count($rows) - count($shown);

        return implode('; ', $parts) . ($more > 0 ? " (+{$more} weitere)" : '');
    }

    /**
     * Objekte (Rechte, Prüfpunkte) in einzelne Schlüssel auflösen, Listen bleiben ganz.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function flatten(array $values): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                foreach (Arr::dot($value) as $subKey => $subValue) {
                    $flat["{$key}.{$subKey}"] = $subValue;
                }
            } else {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    /** Name zu einer ID, je Anfrage zwischengespeichert (im Container, nicht statisch). */
    private static function name(string $field, int $id): ?string
    {
        if (!app()->bound('audit.names')) {
            app()->instance('audit.names', new ArrayObject());
        }
        $names = app('audit.names');
        $cacheKey = "{$field}:{$id}";
        if (!$names->offsetExists($cacheKey)) {
            [$class, $columns] = self::REFERENCES[$field];
            $row = $class::query()->whereKey($id)->first((array) $columns);
            $names[$cacheKey] = $row === null ? null
                : trim(implode(' ', array_map(fn (string $column): string => (string) $row->getAttribute($column), (array) $columns)));
        }

        return $names[$cacheKey];
    }
}
