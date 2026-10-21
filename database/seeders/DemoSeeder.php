<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\AssignmentRole;
use App\Enums\OptionField;
use App\Enums\Responsible;
use App\Enums\RoomUsage;
use App\Enums\ServiceCode;
use App\Models\AccessCode;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Calendar;
use App\Models\CalendarEntry;
use App\Models\Costing;
use App\Models\Damage;
use App\Models\Daysheet;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventFileTag;
use App\Models\FieldOption;
use App\Models\Inquiry;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\PriceList;
use App\Models\Promoter;
use App\Models\Role;
use App\Models\Room;
use App\Models\SeatingPlan;
use App\Models\Setting;
use App\Models\Trade;
use App\Models\User;
use App\Support\Daysheets;
use App\Support\MeterReadings;
use App\Support\ShowChecklist;
use App\Support\StagePodests;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Demodaten einer erfundenen Venue („Be-In Arena“) für demo.vcontrol.eu:
 * Rollen und Konten, Feldoptionen, Räume, Gewerke, Veranstalter, Preislisten,
 * Saalpläne und rund 35 Events vom Sommer bis ins Frühjahr – relativ zum Tag,
 * an dem der Befehl läuft. Vergangene sind abgeschlossen (Betrieb, Protokolle,
 * Schäden), kommende bestätigt, fraglich oder storniert. Optionen sind Anfragen
 * mit Wunschterminen (calendarData), dazu eigene Einträge auf den Ebenen
 * Wartung und Technik. Zähler Strom (je Event), Wasser und Fernwärme mit
 * Monatsablesungen über zwei Jahre (meterData).
 *
 * Nur über `php artisan vc:demo-data` und nur in einer leeren Installation.
 * Alle Namen sind erfunden, Mailadressen enden auf .example (gehen nirgends
 * hin), Konten haben Zufallspasswörter, die niemand kennt.
 */
final class DemoSeeder extends Seeder
{
    private const DOMAIN = 'be-in-arena.example';

    private Carbon $today;

    private Randomizer $random;

    /** @var array<string, User|Contact> Kürzel => Konto mit Positionen oder Kontakt ohne Anmeldung */
    private array $staff = [];

    /** @var array<string, Promoter> */
    private array $promoters = [];

    /** @var array<string, Trade> */
    private array $trades = [];

    /** @var array<string, PriceList> */
    private array $lists = [];

    /** @var array<string, SeatingPlan> Bestuhlung => Plan */
    private array $plans = [];

    /** @var array<string, Room> */
    private array $rooms = [];

    /** @var list<InventoryItem> */
    private array $inventory = [];

    /** @var list<Article> */
    private array $articles = [];

    private int $nr = 1201;

    private float $meter = 482_350.0;

    private ?Meter $power = null;

    public function __construct(private readonly string $venue = 'Be-In Arena')
    {
    }

    public function run(): void
    {
        $this->today = Carbon::today();
        $this->random = new Randomizer(new Mt19937(2026));

        $this->settings();
        $this->options();
        $this->calendars();
        $this->roles();
        $this->masterData();
        $this->people();
        $this->promoters();
        $this->priceLists();
        $this->seatingPlans();
        foreach (self::EVENTS as $row) {
            $this->event(...$row);
        }
        $this->calendarData($this->today);
        $this->meterData($this->today);
        $this->accessCodes();
        Damage::create(['recorded_at' => $this->today->copy()->subDays(9)->setTime(11, 20), 'description' => 'Notausgangsleuchte im Treppenhaus Nord flackert.', 'recorded_by_name' => 'Jonas Becker', 'is_fixed' => false]);
    }

    /** Wie viele Events angelegt werden (für den Befehl und die Tests) */
    public static function eventCount(): int
    {
        return count(self::EVENTS);
    }

    // ------------------------------------------------------------ Grunddaten

    private function settings(): void
    {
        Setting::put(Setting::VENUE_NAME, $this->venue);
        Setting::put(Setting::MAIL_REPLY_TO, 'info@' . self::DOMAIN);
        Setting::put(Setting::DAYSHEET_TO, 'produktion@' . self::DOMAIN);
        Setting::put(Setting::DAMAGE_NOTIFY, 'haustechnik@' . self::DOMAIN);
        Setting::put('stage_label', 'Be-In Stage');
    }

    private function options(): void
    {
        $values = [
            OptionField::VaStatus->value => ['bestätigt', 'fraglich', 'storniert'],
            OptionField::Areas->value => ['Innenraum', 'Unterrang Nord', 'Unterrang Süd', 'Oberrang', 'Logen'],
            OptionField::Seating->value => ['unbestuhlt', 'teilbestuhlt', 'bestuhlt', 'tba.'],
            OptionField::Ticketing->value => ['freie Platzwahl', 'Innenraum/Rang', 'platzgenau', 'tba.'],
            OptionField::PrStatus->value => ['✓', '✗', 'n.b.'],
            OptionField::VaType1->value => ['Konzert', 'Comedy', 'Show', 'Sport', 'Messe', 'Versammlung', 'Gala'],
            OptionField::EmployeePosition->value => ['VL', 'VfV'],
            OptionField::ContractStatus->value => ['Vertrag erstellt', 'Vertrag versendet', 'Vertrag zurück', 'Rahmenvertrag'],
            OptionField::AccountingStatus->value => ['1. Rate / Grundmiete gestellt', '1. Rate / Grundmiete gezahlt', '2. Rate gestellt', '2. Rate gezahlt', Event::ACCOUNTING_FINAL, 'Endabrechnung gezahlt'],
        ];
        foreach ($values as $field => $list) {
            foreach ($list as $index => $value) {
                FieldOption::create(['field_key' => $field, 'value' => $value, 'sort_order' => $index + 1, 'is_active' => true]);
            }
        }
        $types = [
            'Konzert' => ['Pop / Rock', 'Hard Rock / Metal', 'Hip Hop / R\'n\'B', 'EDM / Electro', 'Schlager', 'Klassik', 'Musical'],
            'Comedy' => ['Stand-up', 'Podcast live'],
            'Show' => ['Zirkus / Artistik', 'Familienshow'],
            'Sport' => ['Basketball', 'Kampfsport'],
            'Messe' => ['Verbrauchermesse', 'Fachmesse'],
            'Versammlung' => ['Hauptversammlung', 'Betriebsversammlung'],
            'Gala' => ['Firmenfeier', 'Ball'],
        ];
        $sort = 0;
        foreach ($types as $parent => $children) {
            foreach ($children as $value) {
                FieldOption::create(['field_key' => OptionField::VaType2->value, 'value' => $value, 'parent_value' => $parent, 'sort_order' => ++$sort, 'is_active' => true]);
            }
        }
        // Einheiten der Preislisten legt eine Migration an; nur falls sie fehlen
        if (!FieldOption::query()->where('field_key', OptionField::CostUnit->value)->exists()) {
            foreach (['pauschal', 'je Stück', 'jeweils', 'pro verkauftem Ticket', 'pro Person/Stunde', 'pro Raum/Tag', 'je Anschluss', '%'] as $index => $unit) {
                FieldOption::create(['field_key' => OptionField::CostUnit->value, 'value' => $unit, 'sort_order' => $index + 1, 'is_active' => true]);
            }
        }
    }

    private function calendars(): void
    {
        $rows = [
            ['personal', 'Persönlich', '#000000', null, true, 0],
            ['events', 'Events', '#C54E4C', 'belegt', true, 10],
            ['veranstaltungsleitung', 'Veranstaltungsleitung', '#FFA200', null, false, 15],
            ['anfragen', 'Anfragen', '#7E221B', null, true, 20],
            ['wartung', 'Wartung & Co.', '#5C2D91', 'zu_klaeren', false, 40],
            ['technik', 'Technik', '#FFD700', null, false, 50],
        ];
        foreach ($rows as [$key, $name, $color, $free, $system, $sort]) {
            Calendar::create(['key' => $key, 'name' => $name, 'color' => $color, 'freitermin_status' => $free, 'is_system' => $system, 'sort_order' => $sort]);
        }
    }

    /** Rollen wie in der ersten Installation; die System-Admin-Rolle legt vc:system-admin an. */
    private function roles(): void
    {
        $all = fn (string $level, array $keys): array => array_fill_keys($keys, $level);
        $calendars = ['personal', 'events', 'veranstaltungsleitung', 'anfragen', 'wartung', 'technik'];
        $work = ['anfragen', 'audit', 'buchhaltung', 'codes', 'events', 'events_operations', 'kalender', 'protokolle', 'schichten', 'veranstalter', 'zaehler'];
        $admin = ['admin_benutzer', 'admin_feldoptionen', 'admin_gewerke', 'admin_inventar', 'admin_kalender', 'admin_mitarbeiter', 'admin_raeume', 'admin_rollen', 'admin_schichtplanung', 'admin_stammdaten'];
        $roles = [
            ['admin', 'Admin', true, true, $all('edit', [...$admin, ...$work]), $all('edit', $calendars)],
            ['eventmanager', 'Eventmanager', true, false, ['anfragen' => 'edit', 'audit' => 'read', 'codes' => 'read', 'events' => 'edit', 'events_operations' => 'edit', 'kalender' => 'edit', 'protokolle' => 'read', 'schichten' => 'edit', 'veranstalter' => 'read', 'zaehler' => 'edit'], $all('edit', $calendars)],
            ['buchhaltung', 'Buchhaltung', true, false, [...$all('read', $work), 'buchhaltung' => 'edit', 'codes' => 'none'], [...$all('read', $calendars), 'personal' => 'edit']],
            ['catering', 'Catering', true, false, ['codes' => 'read', 'events' => 'read', 'kalender' => 'read', 'protokolle' => 'read'], [...$all('read', $calendars), 'personal' => 'edit']],
            ['hausmeister', 'Hausmeister', true, false, ['codes' => 'read', 'events' => 'read', 'events_operations' => 'edit', 'kalender' => 'read', 'protokolle' => 'read', 'schichten' => 'read', 'zaehler' => 'read'], $all('read', $calendars)],
            ['extern', '* extern *', false, false, ['events_extern' => 'read', 'kalender_extern' => 'read', 'schichten_extern' => 'read'], ['events' => 'read']],
        ];
        foreach ($roles as $index => [$slug, $name, $system, $super, $permissions, $calendarPermissions]) {
            Role::create(['slug' => $slug, 'name' => $name, 'is_system' => $system, 'is_super' => $super, 'sort_order' => $index + 1,
                'permissions' => $permissions, 'calendar_permissions' => $calendarPermissions]);
        }
    }

    private function masterData(): void
    {
        foreach (['Backstage 1', 'Backstage 2', 'Backstage 3', 'Backstage 4', 'Produktionsbüro', 'Catering', 'Umkleide A', 'Umkleide B', 'Lounge', 'Pressekonferenzraum'] as $index => $name) {
            $this->rooms[$name] = Room::create(['name' => $name, 'sort_order' => $index + 1, 'is_active' => true]);
        }
        foreach (['Technical Rider', 'Hospitality Rider', 'Bühnenplan', 'Running Order', 'Riggingplot'] as $index => $name) {
            EventFileTag::create(['name' => $name, 'sort_order' => $index + 1]);
        }
        $inventory = [
            'Funkgeräte' => ['Funkgerät 1', 'Funkgerät 2', 'Funkgerät 3', 'Funkgerät 4', 'Funkgerät 5', 'Funkgerät 6'],
            'Schlüssel' => ['Generalschlüssel Backstage', 'Schlüssel Produktionsbüro', 'Schlüssel Ladehof', 'Schlüssel Technikraum'],
            'Kabel' => ['CEE 32 A, 25 m', 'CEE 63 A, 25 m', 'Stromverteiler 32 A'],
            'Werkzeug' => ['Akkuschrauber', 'Schlagschrauber'],
            'Sonstiges' => ['Erste-Hilfe-Koffer', 'Absperrband'],
        ];
        $sort = 0;
        foreach ($inventory as $category => $items) {
            $cat = InventoryCategory::create(['name' => $category, 'sort_order' => ++$sort, 'is_active' => true]);
            foreach ($items as $name) {
                $this->inventory[] = InventoryItem::create(['category_id' => $cat->id, 'name' => $name, 'is_active' => true]);
            }
        }
        $articles = [
            'Getränke' => [['Wasser still 0,5 l', 'Wasser', 'Fl.', 1.20], ['Wasser medium 0,5 l', 'Wasser m.', 'Fl.', 1.20], ['Cola 0,33 l', 'Cola', 'Fl.', 1.80], ['Kaffee (Kanne)', 'Kaffee', 'Kanne', 9.50]],
            'Bühne' => [['Podest 2 × 1 m', 'Podest', 'Stk.', 25.00], ['Treppe', 'Treppe', 'Stk.', 15.00], ['Geländer 2 m', 'Geländer', 'Stk.', 12.00]],
            'Technik' => [['Stromanschluss 32 A', 'Strom 32 A', 'Anschluss', 95.00], ['Stromanschluss 63 A', 'Strom 63 A', 'Anschluss', 140.00], ['Funkgerät (Tag)', 'Funk', 'Tag', 8.00]],
        ];
        $sort = 0;
        foreach ($articles as $category => $items) {
            $cat = ArticleCategory::create(['name' => $category, 'sort_order' => ++$sort, 'is_active' => true]);
            foreach ($items as [$name, $short, $unit, $price]) {
                $this->articles[] = Article::create(['category_id' => $cat->id, 'name' => $name, 'short_name' => $short, 'unit' => $unit, 'price' => $price, 'is_active' => true]);
            }
        }
    }

    private function people(): void
    {
        // Personen mit Konto (Positionen am Konto) – mit Zufallspasswort: Daten für Rollen, Verlauf
        // und Extern-Bereich, anmelden kann sich damit niemand. Ohne Konto: Kontakte.
        $roles = Role::query()->pluck('id', 'slug');
        $accounts = [
            ['MA', 'Mehmet', 'Aydın', ['VL'], 'admin'], ['AK', 'Anna', 'Koch', ['VL'], 'eventmanager'], ['JS', 'Jana', 'Schulz', ['VL'], 'eventmanager'],
            ['SW', 'Sophie', 'Wagner', [], 'buchhaltung'], ['JB', 'Jonas', 'Becker', ['VL'], 'hausmeister'], ['PR', 'Paula', 'Richter', [], 'catering'],
        ];
        foreach ($accounts as [$initials, $first, $last, $positions, $role]) {
            $user = User::create(['first_name' => $first, 'last_name' => $last, 'initials' => $initials, 'email' => Str::lower(Str::ascii($first . '.' . $last)) . '@' . self::DOMAIN,
                'password' => Str::random(40), 'account_type' => AccountType::VenueEmployee, 'positions' => $positions ?: null, 'is_active' => true]);
            $user->roles()->attach($roles[$role]);
            $this->staff[$initials] = $user;
        }
        foreach ([['TM', 'Tom Meyer', ['VfV', 'VL']], ['OP', 'Ole Peters', ['VfV', 'VL']], ['LH', 'Lena Hoffmann', ['VL']]] as [$initials, $name, $positions]) {
            $this->staff[$initials] = Contact::create(['name' => $name, 'initials' => $initials, 'positions' => $positions,
                'email' => Str::lower(Str::ascii(str_replace(' ', '.', $name))) . '@' . self::DOMAIN]);
        }

        $trades = [
            'LW' => ['Lichtwerk Veranstaltungstechnik', ['Technik'], 'licht@lichtwerk.example'],
            'PBN' => ['Podestbau Nord', ['Bühne'], 'dispo@podestbau-nord.example'],
            'RTN' => ['Rigging Team Nord', ['Rigging'], 'rigging@rigging-team-nord.example'],
            'SDH' => ['Sicherheitsdienst Hansen', ['Security'], 'einsatz@sicherheitsdienst-hansen.example'],
            'SAN' => ['Sanitätsdienst Hafenstadt', ['Sanität'], 'leitstelle@sanitaet-hafenstadt.example'],
            'FWN' => ['Feuerschutz Nord', ['Brandwache'], 'wache@feuerschutz-nord.example'],
            'ABS' => ['Absperrtechnik Bauer', ['Absperrung'], 'lager@absperrtechnik-bauer.example'],
            'STS' => ['Stuhlservice Sommer', ['Bestuhlung'], 'auftrag@stuhlservice-sommer.example'],
            'LUC' => ['Löffel & Co. Crew Catering', ['Catering'], 'kueche@loeffel-co.example'],
            'RBB' => ['Reinigung Blitzblank', ['Reinigung'], 'objekt@blitzblank.example'],
        ];
        foreach ($trades as $short => [$name, $categories, $email]) {
            $this->trades[$short] = Trade::create(['short_name' => $short, 'name' => $name, 'categories' => $categories, 'email' => $email, 'city' => 'Hafenstadt']);
        }

        $trade = User::create(['first_name' => 'Max', 'last_name' => 'Brenner', 'email' => 'max.brenner@lichtwerk.example', 'password' => Str::random(40),
            'account_type' => AccountType::TradeAccount, 'trade_id' => $this->trades['LW']->id, 'is_active' => true]);
        $trade->roles()->attach($roles['extern']);
        $freelancer = User::create(['first_name' => 'David', 'last_name' => 'Schmitz', 'initials' => 'DS', 'email' => 'david.schmitz@freelance.example', 'password' => Str::random(40),
            'account_type' => AccountType::Freelancer, 'positions' => ['VfV'], 'is_active' => true]);
        $freelancer->roles()->attach($roles['extern']);
        $this->staff['DS'] = $freelancer;
    }

    private function promoters(): void
    {
        $rows = [
            'KAN' => ['Konzertagentur Nord GmbH', '10012', 'Hamburg', 'konzertagentur-nord.example', [['Pia', 'Brandt', 'Booking'], ['Jan', 'Ludwig', 'Produktion']]],
            'CK' => ['Comedy Kontor GmbH', '10027', 'Köln', 'comedy-kontor.example', [['Lea', 'Sommer', 'Booking']]],
            'ESS' => ['Event-Service Süd GmbH', '10033', 'München', 'event-service-sued.example', [['Markus', 'Feld', 'Produktionsleitung']]],
            'BRT' => ['Bühnenreif Tourneen GmbH', '10041', 'Berlin', 'buehnenreif.example', [['Helena', 'Voss', 'Tourmanagement']]],
            'M&M' => ['Messe & Mehr GmbH', '10058', 'Hannover', 'messe-und-mehr.example', [['Thomas', 'Kruse', 'Projektleitung']]],
            'HL' => ['Hafenstadt Lions Basketball GmbH', '10064', 'Hafenstadt', 'hafenstadt-lions.example', [['Kai', 'Neumann', 'Spielbetrieb'], ['Mira', 'Ostrowski', 'Ticketing']]],
            'KR' => ['Kulturring Hafenstadt e. V.', '10071', 'Hafenstadt', 'kulturring-hafenstadt.example', [['Ingrid', 'Lenz', 'Vorstand']]],
            'TPN' => ['Tour Production Network GmbH', '10085', 'Hamburg', 'tour-production.example', [['Ruben', 'Alves', 'Production Manager']]],
            'GDV' => ['Gala & Dinner Veranstaltungen GmbH', '10092', 'Bremen', 'gala-dinner.example', [['Svenja', 'Rau', 'Projektleitung']]],
        ];
        foreach ($rows as $short => [$name, $customerNo, $city, $domain, $contacts]) {
            $promoter = Promoter::create(['short_name' => $short, 'name' => $name, 'customer_no' => $customerNo, 'email' => 'info@' . $domain, 'city' => $city]);
            foreach ($contacts as $index => [$first, $last, $role]) {
                $promoter->contacts()->create(['first_name' => $first, 'last_name' => $last, 'role' => $role, 'sort_order' => $index + 1,
                    'email' => Str::lower(Str::ascii($first . '.' . $last)) . '@' . $domain]);
            }
            $this->promoters[$short] = $promoter;
        }
    }

    private function priceLists(): void
    {
        $concert = PriceList::create(['name' => 'Konzert-Preisliste 2026', 'valid_from' => '2026-01-01', 'is_active' => true,
            'intro' => 'Grundlage für Konzerte, Comedy und Shows in der ' . $this->venue . '. Alle Preise netto.',
            'notes' => "Alle Preise zzgl. MwSt.\nPosten ohne Preis werden nach Aufwand abgerechnet.\nZahlungsziel: 14 Tage nach Rechnungsdatum."]);
        // Abschnitt, Kostenpunkt, Hinweis, Einheit, Preis, Mindestbetrag, Faktor, PAX, im neuen Costing an
        $items = [
            ['Miete', 'Grundmiete unbestuhlt', 'inkl. Standardbühne 14 × 8 m', 'pauschal', 14500, null, 1, false, true],
            ['Miete', 'Grundmiete bestuhlt', 'Alternative zur Grundmiete unbestuhlt', 'pauschal', 17900, null, 1, false, false],
            ['Miete', 'Auf- oder Abbautag', null, 'jeweils', 3900, null, 0, false, true],
            ['Personal', 'Veranstaltungsleitung', null, 'pro Person/Stunde', 58, null, 14, false, true],
            ['Personal', 'House Rep.', null, 'pro Person/Stunde', 42, null, 20, false, true],
            ['Personal', 'Verantwortliche für Veranstaltungstechnik', null, 'pro Person/Stunde', 48, null, 16, false, true],
            ['Personal', 'Einlasspersonal', null, 'pro Person/Stunde', 29.5, null, 60, false, true],
            ['Technik', 'Hausrigg inkl. Motoren', null, 'pauschal', 2400, null, 1, false, true],
            ['Technik', 'Stromanschluss Nightliner', null, 'je Anschluss', 95, null, 2, false, true],
            ['Technik', 'Stromverbrauch', 'laut Zählerstand', null, null, null, 1, false, true],
            ['Nebenkosten', 'Facility-Fee', null, 'pro verkauftem Ticket', 1.2, 2000, null, true, true],
            ['Nebenkosten', 'Reinigung', null, 'pauschal', 3200, null, 1, false, true],
            ['Nebenkosten', 'Brandsicherheitswache', null, 'pro Person/Stunde', 38, null, 12, false, true],
            ['Nebenkosten', 'Sanitätsdienst', null, null, null, null, 1, false, true],
            ['Zuschläge', 'Sonn- und Feiertagszuschlag', 'auf die Personalkosten', '%', 15, null, 0, false, false],
        ];
        $this->priceListItems($concert, $items);
        $this->lists['konzert'] = $concert;

        $sport = PriceList::create(['name' => 'Sport-Preisliste 2026', 'valid_from' => '2026-01-01', 'is_active' => true,
            'intro' => 'Für Heimspiele und Sportveranstaltungen.', 'notes' => 'Alle Preise zzgl. MwSt.']);
        $this->priceListItems($sport, [
            ['Miete', 'Spieltagsmiete', null, 'pauschal', 6800, null, 1, false, true],
            ['Miete', 'Auf- und Abbau Parkett', null, 'pauschal', 1900, null, 1, false, true],
            ['Personal', 'Einlasspersonal', null, 'pro Person/Stunde', 29.5, null, 40, false, true],
            ['Nebenkosten', 'Facility-Fee', null, 'pro verkauftem Ticket', 0.8, 1000, null, true, true],
            ['Nebenkosten', 'Reinigung', null, 'pauschal', 1800, null, 1, false, true],
        ]);
        $this->lists['sport'] = $sport;
    }

    /** @param  list<array{0: string, 1: string, 2: ?string, 3: ?string, 4: int|float|null, 5: int|null, 6: int|float|null, 7: bool, 8: bool}>  $items */
    private function priceListItems(PriceList $list, array $items): void
    {
        foreach ($items as $index => [$section, $name, $description, $unit, $price, $minimum, $factor, $pax, $active]) {
            $list->items()->create(['section' => $section, 'name' => $name, 'description' => $description, 'unit' => $unit, 'price' => $price,
                'minimum' => $minimum, 'default_factor' => $factor, 'factor_from_pax' => $pax, 'default_active' => $active, 'sort_order' => $index + 1]);
        }
    }

    private function seatingPlans(): void
    {
        // Kapazität so, dass zwei Events darüber liegen und die Warnung zeigen
        $plans = [
            'unbestuhlt' => ['Konzert unbestuhlt', 7000, ['Innenraum', 'Unterrang Nord', 'Unterrang Süd', 'Oberrang']],
            'bestuhlt' => ['Vollbestuhlung', 3500, ['Innenraum', 'Unterrang Nord', 'Unterrang Süd', 'Oberrang', 'Logen']],
            'teilbestuhlt' => ['Sport und Rang', 4500, ['Unterrang Nord', 'Unterrang Süd', 'Oberrang', 'Logen']],
        ];
        foreach ($plans as $seating => [$name, $capacity, $areas]) {
            $path = SeatingPlan::DIRECTORY . '/' . Str::slug($name) . '.pdf';
            Storage::disk(SeatingPlan::DISK)->put($path, $this->seatingPlanPdf($name, $seating));
            $this->plans[$seating] = SeatingPlan::create(['name' => $name, 'capacity' => $capacity, 'path' => $path, 'original_name' => Str::slug($name) . '.pdf',
                'areas' => $areas, 'seating' => [$seating], 'is_active' => true]);
        }
    }

    /** Schematischer Plan als PDF: Bühne, Innenraum, Ränge – genug, um die Funktion zu zeigen. */
    private function seatingPlanPdf(string $name, string $seating): string
    {
        $floor = match ($seating) {
            'bestuhlt' => 'INNENRAUM · Reihen 1–24, Stühle',
            'teilbestuhlt' => 'SPIELFELD',
            default => 'INNENRAUM · Stehplätze',
        };
        $html = '<html><head><style>
            @page { margin: 14mm; } body { font-family: "DejaVu Sans", sans-serif; color: #1f2937; }
            h1 { font-size: 15pt; margin: 0 0 1mm; } p { margin: 0 0 6mm; color: #6b7280; font-size: 9pt; }
            .plan { position: relative; height: 150mm; }
            .box { position: absolute; border: 0.6mm solid #374151; text-align: center; font-size: 9pt; font-weight: bold; }
        </style></head><body>
            <h1>' . e($this->venue) . ' – Saalplan „' . e($name) . '“</h1><p>Beispielplan der Demo-Installation, nicht maßstäblich</p>
            <div class="plan">
                <div class="box" style="left: 10mm; top: 0; width: 247mm; height: 18mm; line-height: 18mm; background: #e5e7eb;">OBERRANG</div>
                <div class="box" style="left: 10mm; top: 24mm; width: 30mm; height: 100mm; line-height: 100mm; background: #f3f4f6;">UNTERRANG NORD</div>
                <div class="box" style="left: 227mm; top: 24mm; width: 30mm; height: 100mm; line-height: 100mm; background: #f3f4f6;">UNTERRANG SÜD</div>
                <div class="box" style="left: 50mm; top: 24mm; width: 167mm; height: 70mm; line-height: 70mm; background: #dbeafe;">' . e($floor) . '</div>
                <div class="box" style="left: 88mm; top: 100mm; width: 91mm; height: 24mm; line-height: 24mm; background: #1f2937; color: #fff;">' . ($seating === 'teilbestuhlt' ? 'KAMPFGERICHT' : 'BÜHNE') . '</div>
                <div class="box" style="left: 10mm; top: 130mm; width: 247mm; height: 14mm; line-height: 14mm; background: #fde68a;">LOGEN</div>
            </div></body></html>';

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot([base_path('vendor/dompdf/dompdf')]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    // ---------------------------------------------------------------- Events

    /**
     * Tage ab heute, Titel, Veranstalter, Kategorie 1 und 2, PAX, Bestuhlung,
     * Bühne [Breite, Tiefe, Höhe], Preisliste, Beginn, Tage, Status, Merkmal.
     *
     * @var list<array{0: int, 1: string, 2: string, 3: string, 4: ?string, 5: int, 6: string, 7: ?array{0: int, 1: int, 2: float}, 8: ?string, 9: string, 10: int, 11: string, 12?: string}>
     */
    private const EVENTS = [
        [-96, 'Sommer-Open-Air: Neon Coast', 'KAN', 'Konzert', 'Pop / Rock', 4200, 'unbestuhlt', [16, 10, 1.6], 'konzert', '20:00', 1, 'bestätigt'],
        [-89, 'Hafenstadt Lions – Testspiel', 'HL', 'Sport', 'Basketball', 2100, 'teilbestuhlt', null, 'sport', '19:00', 1, 'bestätigt'],
        [-82, 'Comedy Night: Lachen am Hafen', 'CK', 'Comedy', 'Stand-up', 1900, 'bestuhlt', [10, 6, 1.0], 'konzert', '20:00', 1, 'bestätigt'],
        [-75, 'Iron Saints – Live 2026', 'TPN', 'Konzert', 'Hard Rock / Metal', 5200, 'unbestuhlt', [16, 10, 1.6], 'konzert', '19:30', 1, 'bestätigt', 'award'],
        [-68, 'Betriebsversammlung Nordwerk AG', 'M&M', 'Versammlung', 'Betriebsversammlung', 1500, 'bestuhlt', [8, 4, 0.8], null, '09:00', 1, 'bestätigt'],
        [-61, 'Schlagernacht des Jahres', 'ESS', 'Konzert', 'Schlager', 4800, 'teilbestuhlt', [14, 8, 1.4], 'konzert', '19:00', 1, 'bestätigt', 'damage'],
        [-54, 'Hafenstadt Lions vs. Riverside Rockets', 'HL', 'Sport', 'Basketball', 3100, 'teilbestuhlt', null, 'sport', '19:00', 1, 'bestätigt'],
        [-47, 'Klassik in der Arena: Filmmusik-Gala', 'KR', 'Konzert', 'Klassik', 3600, 'bestuhlt', [18, 12, 1.0], 'konzert', '19:30', 1, 'bestätigt'],
        [-40, 'Verbrauchermesse Haus & Garten', 'M&M', 'Messe', 'Verbrauchermesse', 6500, 'unbestuhlt', null, null, '10:00', 3, 'bestätigt'],
        [-33, 'Hafenstadt Lions vs. Bay City Hawks', 'HL', 'Sport', 'Basketball', 3300, 'teilbestuhlt', null, 'sport', '18:00', 1, 'bestätigt'],
        [-26, 'MC Polarstern – Kaltfront Tour', 'TPN', 'Konzert', 'Hip Hop / R\'n\'B', 5600, 'unbestuhlt', [14, 8, 1.6], 'konzert', '20:00', 1, 'bestätigt', 'damage'],
        [-19, 'Comedy Kontor: Mixed Show', 'CK', 'Comedy', 'Stand-up', 2400, 'bestuhlt', [10, 6, 1.0], 'konzert', '20:00', 1, 'bestätigt'],
        [-12, 'Hafenstadt Lions vs. Lakeside Lynx', 'HL', 'Sport', 'Basketball', 3400, 'teilbestuhlt', null, 'sport', '19:00', 1, 'bestätigt'],
        [-6, 'Musical: Die Leuchtturmwärterin', 'BRT', 'Konzert', 'Musical', 2800, 'bestuhlt', [16, 10, 1.4], 'konzert', '19:30', 3, 'bestätigt', 'damage'],
        [-2, 'Electro Harbour Night', 'KAN', 'Konzert', 'EDM / Electro', 6000, 'unbestuhlt', [16, 10, 1.6], 'konzert', '21:00', 1, 'bestätigt'],
        [3, 'Hafenstadt Lions vs. Northern Stars', 'HL', 'Sport', 'Basketball', 3200, 'teilbestuhlt', null, 'sport', '19:00', 1, 'bestätigt'],
        [6, 'Rock im Herbst: The Velvet Engines', 'KAN', 'Konzert', 'Pop / Rock', 4500, 'unbestuhlt', [14, 8, 1.4], 'konzert', '20:00', 1, 'bestätigt'],
        [10, 'Comedy Live: Ganz normal verrückt', 'CK', 'Comedy', 'Stand-up', 2200, 'bestuhlt', [10, 6, 1.0], 'konzert', '20:00', 1, 'bestätigt', 'alert'],
        [13, 'Fachmesse Veranstaltungstechnik', 'M&M', 'Messe', 'Fachmesse', 3000, 'unbestuhlt', null, null, '09:00', 2, 'bestätigt'],
        [17, 'Hafenstadt Lions vs. Riverside Rockets', 'HL', 'Sport', 'Basketball', 3300, 'teilbestuhlt', null, 'sport', '19:00', 1, 'bestätigt'],
        [20, 'Schlager-Herbstfest', 'ESS', 'Konzert', 'Schlager', 4200, 'teilbestuhlt', [14, 8, 1.4], 'konzert', '18:30', 1, 'bestätigt'],
        [27, '100 Jahre Nordwerk AG', 'GDV', 'Gala', 'Firmenfeier', 1400, 'bestuhlt', [12, 6, 0.8], 'konzert', '18:00', 1, 'bestätigt'],
        [31, 'Hafenstadt Lions vs. Bay City Hawks', 'HL', 'Sport', 'Basketball', 3400, 'teilbestuhlt', null, 'sport', '19:00', 1, 'bestätigt'],
        [34, 'Podcast live: Zwei Stühle, ein Mikro', 'CK', 'Comedy', 'Podcast live', 1600, 'bestuhlt', [8, 4, 1.0], 'konzert', '20:00', 1, 'fraglich'],
        [42, 'Hafenstadt Lions vs. Lakeside Lynx', 'HL', 'Sport', 'Basketball', 3500, 'teilbestuhlt', null, 'sport', '19:00', 1, 'bestätigt'],
        [45, 'Adventskonzert der Kantorei', 'KR', 'Konzert', 'Klassik', 2100, 'bestuhlt', [14, 8, 1.0], 'konzert', '18:00', 1, 'bestätigt'],
        [52, 'Weihnachtsgala des Sports', 'GDV', 'Gala', 'Ball', 1200, 'bestuhlt', [10, 6, 0.8], 'konzert', '19:00', 1, 'fraglich'],
        [59, 'Hafenstadt Lions – Weihnachtsspiel', 'HL', 'Sport', 'Basketball', 3500, 'teilbestuhlt', null, 'sport', '18:00', 1, 'bestätigt'],
        [66, 'Silvester: Arena Countdown', 'KAN', 'Konzert', 'EDM / Electro', 7000, 'unbestuhlt', [16, 10, 1.6], 'konzert', '21:00', 1, 'bestätigt'],
        [80, 'Neujahrskonzert: Strauß & Co.', 'KR', 'Konzert', 'Klassik', 3200, 'bestuhlt', [18, 12, 1.0], 'konzert', '18:00', 1, 'bestätigt'],
        [87, 'Hafenstadt Lions vs. Northern Stars', 'HL', 'Sport', 'Basketball', 3300, 'teilbestuhlt', null, 'sport', '19:00', 1, 'bestätigt'],
        [101, 'Hauptversammlung Nordwerk AG', 'M&M', 'Versammlung', 'Hauptversammlung', 1800, 'bestuhlt', [12, 6, 0.8], null, '10:00', 1, 'bestätigt'],
        [108, 'Hafenstadt Lions vs. Riverside Rockets', 'HL', 'Sport', 'Basketball', 3400, 'teilbestuhlt', null, 'sport', '19:00', 1, 'bestätigt'],
        [129, 'Hafenstadt Lions vs. Bay City Hawks', 'HL', 'Sport', 'Basketball', 3500, 'teilbestuhlt', null, 'sport', '19:00', 1, 'bestätigt'],
        [136, 'Indie-Festival Hafenklang', 'KAN', 'Konzert', 'Pop / Rock', 4000, 'unbestuhlt', [18, 12, 1.6], 'konzert', '16:00', 2, 'fraglich'],
        [143, 'Verbrauchermesse Frühling', 'M&M', 'Messe', 'Verbrauchermesse', 6000, 'unbestuhlt', null, null, '10:00', 3, 'bestätigt'],
        [150, 'Harbour Fight Night', 'TPN', 'Sport', 'Kampfsport', 3800, 'teilbestuhlt', [8, 8, 0.6], 'sport', '19:00', 1, 'storniert'],
    ];

    /** @param  array{0: int, 1: int, 2: float}|null  $stage */
    private function event(int $offset, string $title, string $promoterKey, string $type1, ?string $type2, int $pax, string $seating, ?array $stage, ?string $listKey, string $time, int $days, string $status, string $mark = ''): void
    {
        $start = $this->today->copy()->addDays($offset)->setTimeFromTimeString($time);
        $lastDay = $start->copy()->addDays($days - 1);
        $past = $lastDay->lt($this->today);
        $soon = !$past && $start->lte($this->today->copy()->addDays(21));
        $tentative = in_array($status, ['fraglich', 'storniert'], true);
        // Wie weit die Planung ist: 3 komplett, 2 weit, 1 Eckdaten, 0 nur angelegt
        $fill = match (true) {
            $tentative => 1,
            $past, $soon => 3,
            $offset <= 60 => 2,
            $offset <= 110 => 1,
            default => 0,
        };
        $promoter = $this->promoters[$promoterKey];
        $sport = $type1 === 'Sport';
        $leads = ['AK', 'JS', 'MA'];
        $lead = $this->staff[$leads[$this->nr % 3]];

        $event = Event::create([
            'va_nr' => $this->nr++,
            'title' => $title,
            'promoter_id' => $promoter->id,
            'status' => $status,
            'event_type1' => $type1,
            'event_type2' => $type2,
            'starts_at' => $start,
            'ends_at' => $days > 1 ? $lastDay->copy()->setTime(22, 0) : null,
            'pax_expected' => $pax,
            'pax' => $past ? (int) round($pax * $this->random->getInt(86, 100) / 100) : null,
            'areas' => $this->plans[$seating]?->areas ?? ['Innenraum'],
            'seating' => [$seating],
            'ticketing' => match ($seating) {
                'bestuhlt' => 'platzgenau',
                'teilbestuhlt' => 'Innenraum/Rang',
                default => 'freie Platzwahl',
            },
            'seating_plan_id' => $fill >= 1 ? $this->plans[$seating]?->id : null,
            'price_list_id' => $listKey !== null ? $this->lists[$listKey]->id : null,
            // Ansprechpartner vor Ort: der letzte Ansprechpartner des Veranstalters
            'onsite_first_name' => $fill >= 2 ? $promoter->contacts->last()?->first_name : null,
            'onsite_last_name' => $fill >= 2 ? $promoter->contacts->last()?->last_name : null,
            'onsite_phone' => $fill >= 2 ? $promoter->contacts->last()?->phone : null,
            'onsite_email' => $fill >= 2 ? $promoter->contacts->last()?->email : null,
            'wlan' => $fill >= 2 ? 'BIA-Production' : null,
            'wlan_password' => $fill >= 2 ? 'Hafenblick-' . $this->random->getInt(1000, 9999) : null,
            'closed' => $past && $offset < -7,
        ]);

        $event->assignments()->create(['role' => AssignmentRole::ProjectLead, ...$this->assignee($lead)]);
        $this->finance($event, $fill, $past, $mark, $offset);
        if ($fill >= 1) {
            $event->pr()->create(['pr_status' => $tentative ? 'n.b.' : '✓', 'pr_date' => $tentative ? null : $start->copy()->subDays(120)]);
            $event->schedule()->create([
                'admission' => $sport ? '17:30' : $start->copy()->subMinutes(90)->format('H:i'),
                'start_time' => $start->format('H:i'),
                'end_time' => $sport ? '21:00' : $start->copy()->addHours(3)->format('H:i'),
            ]);
        }
        if ($stage !== null && $fill >= 1) {
            $this->stage($event, $stage, $past && $mark === 'award');
        }
        if ($fill >= 2) {
            $this->planning($event, $start, $seating, $sport);
        }
        if ($listKey !== null && $fill >= 2) {
            $this->costing($event, $lead, $fill === 3 || $past, $start);
        }
        if ($past) {
            $this->execution($event, $start, $lead, $sport, $mark);
        }
        if ($fill === 3 && !$tentative && ($past || $start->lte($this->today->copy()->addDays(7)))) {
            $this->daysheet($event, $past ? $start->copy()->subDays(3) : $this->today->copy()->subDay()->setTime(16, 30));
        }
    }

    private function finance(Event $event, int $fill, bool $past, string $mark, int $offset): void
    {
        if ($fill === 0) {
            return;
        }
        $accounting = match (true) {
            // Abgeschlossene Abrechnung: auch die Endabrechnung bezahlt (grüner Haken in der Buchhaltungsliste)
            $past && $offset < -30 => ['1. Rate / Grundmiete gestellt', '1. Rate / Grundmiete gezahlt', '2. Rate gestellt', '2. Rate gezahlt', Event::ACCOUNTING_FINAL, 'Endabrechnung gezahlt'],
            $past => ['1. Rate / Grundmiete gestellt', '1. Rate / Grundmiete gezahlt', '2. Rate gestellt', '2. Rate gezahlt', Event::ACCOUNTING_FINAL],
            $fill === 3 && $mark !== 'alert' => ['1. Rate / Grundmiete gestellt', '1. Rate / Grundmiete gezahlt', '2. Rate gestellt', '2. Rate gezahlt'],
            $fill >= 2 => ['1. Rate / Grundmiete gestellt', '1. Rate / Grundmiete gezahlt'],
            default => [],
        };
        $event->finance()->create([
            'contract_status' => match (true) {
                $fill >= 3, $past => 'Vertrag zurück',
                $fill === 2 => 'Vertrag versendet',
                default => 'Vertrag erstellt',
            },
            'accounting_status' => $accounting,
            'rent' => $event->price_list_id !== null ? null : 9500,
            'invoice_numbers' => $past ? ['RE-' . $event->starts_at->format('Y') . '-' . str_pad((string) (300 + $event->va_nr % 300), 4, '0', STR_PAD_LEFT)] : [],
            'accounting_closed' => $past && $offset < -30,
        ]);
    }

    /** @param  array{0: int, 1: int, 2: float}  $stage */
    private function stage(Event $event, array $stage, bool $award): void
    {
        [$width, $depth, $height] = $stage;
        $values = ['width' => $width, 'depth' => $depth, 'height' => $height];
        if ($width >= 16) {
            $values += ['wing_sl_width' => 4, 'wing_sl_depth' => 4, 'wing_sr_width' => 4, 'wing_sr_depth' => 4, 'wing_sl_offset' => 0, 'wing_sr_offset' => 0];
        }
        if ($width <= 10) {
            $values += ['rollpodest_width' => 0, 'rollpodest_depth' => 0];
        }
        $values['podest_total'] = StagePodests::calculate($values)['total'];
        $values['sold_out_award'] = $award ? true : null;
        $event->stage()->create($values);
    }

    private function planning(Event $event, Carbon $start, string $seating, bool $sport): void
    {
        $event->schedule()->update(['get_in' => '08:00', 'load_in' => $sport ? '12:00' : '10:00', 'curfew' => $sport ? null : '23:30', 'load_out' => $sport ? '22:00' : '00:30']);
        $event->checklist()->create(['hands' => 'yes', 'traffic' => 'yes', 'pvc_setup' => $seating === 'unbestuhlt' ? 'yes' : 'na',
            'cleaning' => 'yes', 'interim_cleaning' => 'na', 'bar_setup' => 'yes', 'chairs_ordered' => $seating === 'bestuhlt' ? 'yes' : 'na']);

        $early = $this->staff[$this->random->pickArrayKeys(['TM' => 1, 'OP' => 1, 'LH' => 1], 1)[0]];
        $late = $this->staff[$this->random->pickArrayKeys(['JB' => 1, 'OP' => 1, 'LH' => 1], 1)[0]];
        foreach ([
            [AssignmentRole::Unlock, $this->assignee($early), '07:30', null],
            [AssignmentRole::HouseRepEarly, $this->assignee($early), '08:00', '16:00'],
            [AssignmentRole::HouseRepLate, $this->assignee($late), '16:00', $sport ? '22:30' : '01:00'],
            [AssignmentRole::Lock, $this->assignee($late), null, $sport ? '23:00' : '01:30'],
            [AssignmentRole::SafetyEarly, $this->assignee($this->staff['TM']), '08:00', '16:00'],
            [AssignmentRole::SafetyLate, ['assignee_type' => 'trade', 'assignee_id' => $this->trades['SDH']->id], '16:00', '00:30'],
        ] as [$role, $assignee, $from, $until]) {
            $event->assignments()->create(['role' => $role, ...$assignee, 'starts_at' => $from, 'ends_at' => $until]);
        }

        $services = [
            [ServiceCode::Vt, 'LW', Responsible::Promoter, $sport ? 'nur Hallenbeschallung' : null],
            [ServiceCode::Security, 'SDH', Responsible::Arena, null],
            [ServiceCode::Sanitary, 'SAN', Responsible::Arena, null],
            [ServiceCode::FireWatch, 'FWN', Responsible::Arena, null],
            [ServiceCode::BarriersSetup, 'ABS', Responsible::Arena, $seating === 'unbestuhlt' ? 'Wellenbrecher Innenraum' : null],
            [ServiceCode::CrewCatering, 'LUC', Responsible::Promoter, null],
        ];
        if (!$sport) {
            $services[] = [ServiceCode::StageSetup, 'PBN', Responsible::Arena, 'fertig bis 12 Uhr'];
            $services[] = [ServiceCode::StageTeardown, 'PBN', Responsible::Arena, null];
            $services[] = [ServiceCode::HouseRigIn, 'RTN', Responsible::Arena, null];
        }
        if ($seating === 'bestuhlt') {
            $services[] = [ServiceCode::ChairsSetup, 'STS', Responsible::Arena, 'Reihenplan im Saalplan'];
            $services[] = [ServiceCode::ChairsTeardown, 'STS', Responsible::Arena, null];
        }
        foreach ($services as [$code, $trade, $responsible, $note]) {
            $event->services()->create(['service' => $code, 'trade_id' => $this->trades[$trade]->id, 'responsible' => $responsible, 'is_active' => true, 'note' => $note]);
        }

        $event->backstageRooms()->attach([$this->rooms['Backstage 1']->id, $this->rooms['Backstage 2']->id, $this->rooms['Backstage 3']->id]);
        $event->officeRooms()->attach([$this->rooms['Produktionsbüro']->id]);

        $event->notes()->create(['subject' => 'Anlieferung', 'body' => $sport
            ? "Parkettaufbau ab 12 Uhr, Mannschaftsbusse über Tor 3.\nKampfgericht und Kamera-Podest wie beim letzten Heimspiel."
            : "Anlieferung über Tor 2, Nightliner auf P3 (2 Stromanschlüsse).\nCatering für 40 Personen in Backstage 1."]);
        $event->notes()->create(['subject' => 'Intern', 'body' => 'Abrechnung der Merch-Fee über die Agentur, 20 % auf den Bruttoumsatz.', 'hidden_from_externals' => true]);

        if (!$sport && in_array($event->event_type1, ['Konzert', 'Comedy', 'Show'], true)) {
            $guests = [['Lisa', 'Brandt', 4], ['Jonas', 'Wolf', 2], ['Merle', 'Hansen', 2], ['Can', 'Yilmaz', 1], ['Greta', 'Lindqvist', 2], ['Felix', 'Mertens', 2], ['Ida', 'Schäfer', 1], ['Noah', 'Krüger', 2]];
            foreach (array_slice($guests, 0, $this->random->getInt(4, 8)) as [$first, $last, $tickets]) {
                $event->guests()->create(['first_name' => $first, 'last_name' => $last, 'free_tickets' => $tickets]);
            }
        }
    }

    private function costing(Event $event, User $lead, bool $sent, Carbon $start): void
    {
        $costing = Costing::createFor($event, $this->lists[$event->event_type1 === 'Sport' ? 'sport' : 'konzert']);
        foreach ($costing->items as $item) {
            if ($item->name === 'Auf- oder Abbautag' && $event->ends_at !== null) {
                $item->update(['factor' => 2]);
            }
            if ($item->name === 'Grundmiete unbestuhlt' && $event->seating === ['bestuhlt']) {
                $item->update(['is_active' => false]);
            }
            if ($item->name === 'Grundmiete bestuhlt' && $event->seating === ['bestuhlt']) {
                $item->update(['is_active' => true]);
            }
        }
        if ($sent) {
            $contact = $event->promoter?->contacts->first();
            $costing->update(['sent_at' => $start->copy()->subDays(30)->setTime(10, 15), 'sent_to' => array_filter([$contact?->email]),
                'sent_by_name' => $lead->first_name . ' ' . $lead->last_name]);
        }
    }

    private function execution(Event $event, Carbon $start, User $lead, bool $sport, string $mark): void
    {
        $consumption = $this->random->getInt($sport ? 900 : 2400, $sport ? 1600 : 5200);
        $event->operation()->create([
            'bus_power' => $sport ? 0 : $this->random->getInt(1, 3),
            'house_delay' => !$sport && $this->random->getInt(0, 3) === 0,
            'person_lift' => !$sport,
        ]);
        // Durchführung vollständig erfasst (ExecutionCheck): auch „kein Sold-Out-Award“ ist ein Wert
        $event->stage()->updateOrCreate([], ['sold_out_award' => (bool) $event->stage()->value('sold_out_award')]);
        // Stromzähler (je Event): Stand Anfang und Ende, dazwischen die Zeit bis zum nächsten Event
        $this->power ??= Meter::query()->where('name', 'Strom')->first()
            ?? Meter::create(['name' => 'Strom', 'unit' => 'kWh', 'per_event' => true, 'sort_order' => 10]);
        MeterReadings::saveForEvent($event, $this->power, MeterReading::START, $this->meter);
        MeterReadings::saveForEvent($event, $this->power, MeterReading::END, $this->meter + $consumption);
        $this->meter += $consumption + $this->random->getInt(200, 900);
        $event->checklist()->updateOrCreate([], ['special_cleaning' => 'no', 'power_ant' => false, 'house_rig_early' => !$sport,
            'merch_fee' => $sport ? 'keine' : '20 %', 'merch_fee_check' => $sport ? 'na' : 'yes']);

        $checks = [];
        foreach (array_keys(ShowChecklist::labels()) as $index => $key) {
            $checks[$key] = ['value' => $index === 4 && $mark === 'damage' ? 'no' : 'yes', 'note' => $index === 4 && $mark === 'damage' ? 'zwei Barriers fehlen, beim Veranstalter angefragt' : null];
        }
        $end = $start->copy()->addHours(4);
        $this->stamp($event->showChecklists()->create(['checked_at' => $end, 'house_rep' => $lead->first_name . ' ' . $lead->last_name, 'house_rep_signature' => $this->signature(1),
            'promoter_rep' => $event->onsiteContactName() ?? 'Produktionsleitung', 'promoter_rep_signature' => $this->signature(2), 'checks' => $checks,
            'remarks' => $mark === 'damage' ? 'Schaden aufgenommen, siehe Schäden.' : null]), $lead);

        $protocol = $this->stamp($event->handoverProtocols()->create(['handed_to' => $sport ? 'Kai Neumann (HL)' : 'Max Brenner (Lichtwerk)', 'handed_at' => $start->copy()->setTime(9, 0),
            'signature' => $this->signature(3), 'status' => 'returned', 'returned_at' => $end, 'returned_by' => $lead->id]), $lead);
        foreach (array_slice($this->inventory, 0, 5) as $index => $item) {
            $protocol->items()->create(['inventory_item_id' => $item->id, 'category_id' => $item->category_id, 'category_name' => $item->category?->name,
                'item_name' => $item->name, 'sort_order' => $index + 1]);
        }

        if (!$sport) {
            $slip = $this->stamp($event->orderSlips()->create(['ordered_from' => $event->onsiteContactName() ?? 'Produktion', 'ordered_at' => $start->copy()->setTime(14, 30),
                'signature' => $this->signature(4), 'is_settled' => $event->closed]), $lead);
            foreach ([[$this->articles[0], 48], [$this->articles[2], 24], [$this->articles[7], 2]] as $index => [$article, $quantity]) {
                $slip->items()->create(['article_id' => $article->id, 'category_id' => $article->category_id, 'category_name' => $article->category?->name,
                    'article_name' => $article->name, 'unit' => $article->unit, 'unit_price' => $article->price, 'quantity' => $quantity,
                    'line_total' => round((float) $article->price * $quantity, 2), 'sort_order' => $index + 1]);
            }
        }

        if ($mark === 'damage') {
            $damages = [
                'Schlagernacht des Jahres' => ['Rolltor Ladehof: Führungsschiene verbogen, Tor läuft schwer.', true],
                'MC Polarstern – Kaltfront Tour' => ['Kabelkanal im Innenraum gebrochen (Höhe Mischpult).', false],
                'Musical: Die Leuchtturmwärterin' => ['Backstage 2: Spiegel gesprungen.', false],
            ];
            [$description, $fixed] = $damages[$event->title] ?? ['Kratzer im Bühnenboden.', true];
            $event->damages()->create(['recorded_at' => $end, 'description' => $description, 'is_fixed' => $fixed, 'recorded_by_name' => 'Jonas Becker']);
        }
    }

    private function daysheet(Event $event, Carbon $sentAt): void
    {
        $daysheet = Daysheet::create([
            'event_id' => $event->id,
            'token_hash' => hash('sha256', Str::random(48)),
            'recipients_to' => ['produktion@' . self::DOMAIN],
            'recipients_bcc' => Daysheets::tradeEmails($event),
            'subject' => Daysheets::fill(Daysheets::DEFAULT_SUBJECT, $event),
            'body' => Daysheets::fill(Daysheets::DEFAULT_TEXT, $event, '(Link)'),
            'expires_at' => Daysheets::validUntil($event),
        ]);
        $daysheet->forceFill(['created_at' => $sentAt, 'updated_at' => $sentAt])->saveQuietly();
    }

    /**
     * Anfragen (Optionen) und eigene Kalendereinträge, relativ zu $anchor – beim
     * Befüllen heute, für eine schon befüllte Demo der Tag, an dem sie befüllt
     * wurde. Manche Anfragen teilen sich einen Tag (1. und 2. Option), eine fällt
     * auf ein bestätigtes Event (nicht verfügbar), eine ist zurückgezogen, eine
     * bestätigt.
     */
    public function calendarData(Carbon $anchor): void
    {
        $this->today = $anchor->copy()->startOfDay();
        $this->random ??= new Randomizer(new Mt19937(2027));
        $promoters = Promoter::query()->get()->keyBy('short_name');
        $author = User::query()->whereHas('roles', fn ($roles) => $roles->where('slug', 'eventmanager'))->orderBy('id')->first();

        // Titel, Veranstalter, Kategorie 1 und 2, Bestuhlung, Tage ab $anchor, Eingang vor … Tagen, Status
        $rows = [
            ['Iron Saints – Zusatzshow', 'TPN', 'Konzert', 'Hard Rock / Metal', 'unbestuhlt', [24, 25], 21, Inquiry::OPEN],
            ['Weihnachtscircus Funkelstern', 'BRT', 'Show', 'Zirkus / Artistik', 'bestuhlt', [38, 39, 40, 41], 30, Inquiry::OPEN],
            ['Electro Winter Rave', 'KAN', 'Konzert', 'EDM / Electro', 'unbestuhlt', [49, 50], 18, Inquiry::OPEN],
            ['Hochzeitsmesse Hafenstadt', 'M&M', 'Messe', 'Verbrauchermesse', 'unbestuhlt', [49], 9, Inquiry::OPEN],
            ['MC Polarstern – Zusatzkonzert', 'TPN', 'Konzert', 'Hip Hop / R\'n\'B', 'unbestuhlt', [94, 95], 12, Inquiry::OPEN],
            ['Comedy Kontor: Frühjahrs-Mixed-Show', 'CK', 'Comedy', 'Stand-up', 'bestuhlt', [115], 25, Inquiry::OPEN],
            ['Harbour Fight Night – Revanche', 'TPN', 'Sport', 'Kampfsport', 'teilbestuhlt', [115, 116], 6, Inquiry::OPEN],
            ['Musical: Die Leuchtturmwärterin kehrt zurück', 'BRT', 'Konzert', 'Musical', 'bestuhlt', [122, 123, 124], 40, Inquiry::OPEN],
            ['The Velvet Engines – Akustik', 'KAN', 'Konzert', 'Pop / Rock', 'bestuhlt', [157, 164], 15, Inquiry::OPEN],
            ['Abiball Hafenstadt', 'GDV', 'Gala', 'Ball', 'bestuhlt', [171], 33, Inquiry::OPEN],
            ['Schlagerparty XXL', 'ESS', 'Konzert', 'Schlager', 'teilbestuhlt', [20, 62], 5, Inquiry::OPEN],
            ['Logistik-Gala Nord', 'GDV', 'Gala', 'Firmenfeier', 'bestuhlt', [33], 45, Inquiry::WITHDRAWN],
        ];
        foreach ($rows as [$title, $promoter, $type1, $type2, $seating, $days, $received, $status]) {
            $inquiry = Inquiry::create([
                'title' => $title,
                'promoter_id' => $promoters->get($promoter)?->id,
                'option_deadline' => $this->today->copy()->subDays($received)->addDays(Inquiry::DEADLINE_DAYS),
                'comment' => $status === Inquiry::WITHDRAWN ? 'Veranstalter hat sich für eine andere Halle entschieden.' : null,
                'event_type1' => $type1,
                'event_type2' => $type2,
                'seating' => [$seating],
                'areas' => ($this->plans[$seating] ?? SeatingPlan::query()->whereJsonContains('seating', $seating)->first())?->areas ?? [],
                'status' => $status,
            ]);
            foreach ($days as $day) {
                $inquiry->dates()->create(['option_date' => $this->today->copy()->addDays($day)->toDateString()]);
            }
            $received = $this->today->copy()->subDays($received)->setTime(10, 30);
            $inquiry->forceFill(['created_at' => $received, 'updated_at' => $received, 'created_by' => $author?->id, 'created_by_name' => $author?->getFilamentName()])->saveQuietly();
        }

        // Bestätigt: aus einer Anfrage wurde das Adventskonzert
        $advent = Event::query()->where('title', 'Adventskonzert der Kantorei')->first();
        if ($advent !== null) {
            $inquiry = Inquiry::create(['title' => $advent->title, 'promoter_id' => $advent->promoter_id, 'event_type1' => $advent->event_type1,
                'event_type2' => $advent->event_type2, 'seating' => $advent->seating, 'areas' => $advent->areas, 'status' => Inquiry::CONFIRMED,
                'event_id' => $advent->id, 'option_deadline' => $this->today->copy()->subDays(70)]);
            $date = $inquiry->dates()->create(['option_date' => $advent->starts_at->toDateString()]);
            $inquiry->forceFill(['confirmed_date_id' => $date->id, 'created_at' => $this->today->copy()->subDays(98), 'updated_at' => $this->today->copy()->subDays(80)])->saveQuietly();
        }

        // Eigene Einträge: Ebene, Titel, Tage ab $anchor, bis Tag, Beginn, Ende, Beschreibung
        $entries = [
            ['wartung', 'Brandschutzbegehung', -20, null, '10:00', '12:00', 'Mit der Feuerwehr, alle Notausgänge offen halten.'],
            ['wartung', 'Wartung Lüftungsanlage', 8, null, null, null, 'Halle ab 7 Uhr ohne Lüftung, keine Belegung.'],
            ['wartung', 'Prüfung Rigging-Punkte', 15, null, '08:00', '12:00', 'Sachverständiger vor Ort, Hallendecke gesperrt.'],
            ['wartung', 'Aufzug: TÜV', 30, null, '09:00', '10:00', null],
            ['wartung', 'Hallenboden versiegeln', 56, 58, null, null, 'Drei Tage keine Belegung im Innenraum.'],
            ['technik', 'Update Lichtpult', 5, null, '14:00', '16:00', null],
            ['technik', 'Inventur Kabel und Funk', 22, null, null, null, null],
            ['technik', 'Einmessung Beschallung', 61, null, '10:00', '15:00', 'Neue Delay-Line unter dem Oberrang.'],
        ];
        $layers = Calendar::query()->pluck('key')->all();
        foreach ($entries as [$layer, $title, $from, $until, $start, $end, $description]) {
            if (!in_array($layer, $layers, true)) {
                continue;
            }
            $day = $this->today->copy()->addDays($from);
            $entry = CalendarEntry::create([
                'calendar_key' => $layer,
                'title' => $title,
                'description' => $description,
                'all_day' => $start === null,
                'starts_at' => $start === null ? $day->copy()->startOfDay() : $day->copy()->setTimeFromTimeString($start),
                'ends_at' => match (true) {
                    $until !== null => $this->today->copy()->addDays($until)->startOfDay(),
                    $end !== null => $day->copy()->setTimeFromTimeString($end),
                    default => null,
                },
            ]);
            $entry->forceFill(['created_by' => $author?->id, 'created_by_name' => $author?->getFilamentName()])->saveQuietly();
        }
    }

    /**
     * Zähler mit Ablesungen am Ersten jedes Monats (erfasst von der
     * Eventmanagerin – der Hausmeister darf Zählerstände nur lesen), zwei Jahre
     * bis $anchor: Strom (je Event; die Monatsstände passen zwischen die Stände
     * der Events), Wasser (mit einem Zählerwechsel) und Fernwärme, jeweils mit
     * Jahreszeiten. Für eine schon befüllte Demo mit dem Tag, an dem sie befüllt
     * wurde; was es schon gibt, bleibt.
     */
    public function meterData(Carbon $anchor): void
    {
        $this->random ??= new Randomizer(new Mt19937(2028));
        $today = $anchor->copy()->startOfDay();
        $first = $today->copy()->startOfMonth()->subMonths(24);
        $by = User::query()->whereHas('roles', fn ($roles) => $roles->where('slug', 'eventmanager'))->orderBy('id')->first();
        $months = [];
        for ($month = $first->copy()->setTime(7, 30); $month->lte($today); $month->addMonth()) {
            $months[] = $month->copy();
        }

        // Strom: Monatsverbrauch ohne Events nach Jahreszeit; zwischen Event-Ständen linear
        $power = Meter::query()->firstOrCreate(['name' => 'Strom'], ['unit' => 'kWh', 'per_event' => true, 'sort_order' => 10]);
        $power->update(['number' => $power->number ?? '1EBZ 0104 4715', 'location' => $power->location ?? 'Hauptverteilung UG']);
        if (!$power->readings()->whereNull('event_id')->exists()) {
            $points = $power->readings()->orderBy('read_at')->get()->map(fn (MeterReading $reading): array => [$reading->read_at, (float) $reading->value])->all();
            $values = $this->meterSeries($months, $points, [15500, 14000, 14500, 12500, 12000, 11000, 13500, 13000, 15000, 16500, 17500, 17000], 482_350.0, 0);
            foreach ($values as $index => $value) {
                $this->meterReading($power, $months[$index], $value, $by);
            }
        }

        // Wasser: Zählerwechsel vor neun Monaten – erst der letzte Stand des alten, dann der neue bei fast 0
        $water = Meter::query()->firstOrCreate(['name' => 'Wasser'], ['unit' => 'm³', 'number' => '19-447 812', 'location' => 'Hausanschlussraum', 'sort_order' => 20]);
        if (!$water->readings()->exists()) {
            $values = $this->meterSeries($months, [], [420, 390, 430, 460, 520, 560, 640, 610, 580, 540, 470, 450], 18_452.317, 3);
            $swap = $months[count($months) - 10]->copy()->setDay(15)->setTime(10, 0);
            $offset = null;
            foreach ($values as $index => $value) {
                if ($offset === null && $months[$index]->greaterThan($swap)) {
                    // Altzähler bis zum Wechsel anteilig, dann ab 0,512 m³ weiter
                    $old = round($values[$index - 1] + ($value - $values[$index - 1]) * ($swap->getTimestamp() - $months[$index - 1]->getTimestamp()) / ($months[$index]->getTimestamp() - $months[$index - 1]->getTimestamp()), 3);
                    $this->meterReading($water, $swap, $old, $by, note: 'Letzter Stand des alten Zählers.');
                    $this->meterReading($water, $swap->copy()->addMinutes(5), 0.512, $by, restart: true, note: 'Zählerwechsel durch die Stadtwerke (Eichfrist), neue Zählernummer 21-008 154.');
                    $offset = $old - 0.512;
                }
                $this->meterReading($water, $months[$index], round($value - ($offset ?? 0), 3), $by);
            }
        }

        $heat = Meter::query()->firstOrCreate(['name' => 'Fernwärme'], ['unit' => 'MWh', 'number' => '7731 0098', 'location' => 'Übergabestation Technikzentrale', 'sort_order' => 30]);
        if (!$heat->readings()->exists()) {
            foreach ($this->meterSeries($months, [], [96, 88, 71, 44, 19, 7, 5, 5, 14, 38, 67, 89], 4_210.54, 3) as $index => $value) {
                $this->meterReading($heat, $months[$index], $value, $by);
            }
        }
    }

    /**
     * Stände zu $months: zwischen vorhandenen Ständen ($points, [Zeit, Stand])
     * linear, davor rückwärts und danach vorwärts mit dem Monatsverbrauch nach
     * Jahreszeit (±8 %). Ohne $points beginnt die Reihe bei $start.
     *
     * @param  list<Carbon>  $months
     * @param  list<array{Carbon, float}>  $points
     * @param  list<int>  $perMonth  Verbrauch Januar bis Dezember
     * @return list<float>
     */
    private function meterSeries(array $months, array $points, array $perMonth, float $start, int $decimals): array
    {
        $usage = fn (Carbon $from, Carbon $until): float => $perMonth[$from->month - 1] * $this->random->getInt(92, 108) / 100
            * ($until->getTimestamp() - $from->getTimestamp()) / ($from->daysInMonth * 86400);
        $values = [];
        if ($points === []) {
            $value = $start;
            foreach ($months as $index => $month) {
                $value += $index === 0 ? 0 : $usage($months[$index - 1], $month);
                $values[] = round($value, $decimals);
            }

            return $values;
        }

        [$firstAt, $firstValue] = $points[0];
        [$lastAt, $lastValue] = $points[count($points) - 1];
        // Vor dem ersten Stand: rückwärts vom ersten Stand
        $before = array_values(array_filter($months, fn (Carbon $month): bool => $month->lessThan($firstAt)));
        $value = $firstValue;
        $next = $firstAt;
        foreach (array_reverse($before) as $month) {
            $value -= $usage($month, $next);
            $next = $month;
            $values[$month->getTimestamp()] = round($value, $decimals);
        }
        foreach ($months as $month) {
            if ($month->lessThan($firstAt)) {
                continue;
            }
            if ($month->greaterThan($lastAt)) {
                $lastValue += $usage($lastAt, $month);
                $lastAt = $month;
                $values[$month->getTimestamp()] = round($lastValue, $decimals);

                continue;
            }
            // Zwischen den beiden umgebenden Ständen
            foreach ($points as $index => [$at, $reading]) {
                [$nextAt, $nextReading] = $points[$index + 1] ?? [$at, $reading];
                if ($month->greaterThanOrEqualTo($at) && $month->lessThanOrEqualTo($nextAt)) {
                    $share = $nextAt->equalTo($at) ? 0 : ($month->getTimestamp() - $at->getTimestamp()) / ($nextAt->getTimestamp() - $at->getTimestamp());
                    $values[$month->getTimestamp()] = round($reading + ($nextReading - $reading) * $share, $decimals);

                    break;
                }
            }
        }
        ksort($values);

        return array_values($values);
    }

    private function meterReading(Meter $meter, Carbon $at, float $value, ?User $by, bool $restart = false, ?string $note = null): void
    {
        $reading = $meter->readings()->create(['read_at' => $at, 'value' => $value, 'restart' => $restart, 'note' => $note]);
        if ($by !== null) {
            $this->stamp($reading, $by);
        }
    }

    /** Codes Tageszugang: eine Woche zurück, zwei Monate voraus */
    private function accessCodes(): void
    {
        for ($day = -7; $day <= 60; $day++) {
            $date = $this->today->copy()->addDays($day);
            AccessCode::create(['code' => (string) $this->random->getInt(100000, 999999), 'valid_from' => $date->copy()->setTime(6, 0), 'valid_on' => $date->toDateString()]);
        }
    }

    /**
     * Wer es angelegt hat („übergeben von“, „bei wem“) – sonst setzt das die
     * Anmeldung, die es beim Befüllen nicht gibt.
     *
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    private function stamp(Model $model, User $by): Model
    {
        $model->forceFill(['created_by' => $by->id, 'created_by_name' => $by->getFilamentName()])->saveQuietly();

        return $model;
    }

    /** @return array{assignee_type: string, assignee_id: int} Konto oder Kontakt fürs Personal */
    private function assignee(User|Contact $person): array
    {
        return ['assignee_type' => $person instanceof User ? 'user' : 'contact', 'assignee_id' => (int) $person->id];
    }

    /** Unterschrift als PNG (data:-URL wie SignaturePad): eine Schwungkurve, je Nummer etwas anders */
    private function signature(int $variant): string
    {
        $image = imagecreatetruecolor(360, 120);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagesetthickness($image, 3);
        $ink = imagecolorallocate($image, 30, 41, 82);
        $previous = null;
        for ($x = 20; $x <= 340; $x += 4) {
            $y = (int) round(62 + 28 * sin(($x + $variant * 37) / (18 + $variant * 3)) * cos($x / 70) - ($x - 20) / 16);
            if ($previous !== null) {
                imageline($image, $previous[0], $previous[1], $x, $y, $ink);
            }
            $previous = [$x, $y];
        }
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
