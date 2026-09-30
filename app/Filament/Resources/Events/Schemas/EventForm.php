<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Access\Area;
use App\Access\Level;
use App\Enums\AssignmentRole;
use App\Enums\OptionField;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\RelationManagers\GuestsRelationManager;
use App\Filament\Resources\Events\RelationManagers\NotesRelationManager;
use App\Filament\Support\AssignmentFields;
use App\Filament\Support\IncomingInvoiceFields;
use App\Filament\Support\OptionChoices;
use App\Filament\Support\ServiceFields;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Event;
use App\Models\EventFinance;
use App\Models\EventPr;
use App\Models\EventStage;
use App\Support\EventProgress;
use App\Support\StagePlan;
use App\Support\StagePodests;
use Filament\Actions\Action;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Event-Workspace wie in der PHP-Version: Übersicht (Dashboard) und die drei
 * Phasen, darin die Unterbereiche als eigene Reiter. Reiter stehen in der URL
 * (?phase=planung&bereich=buehne), damit das Dashboard direkt hineinspringt.
 */
class EventForm
{
    /** Zeiten in der Reihenfolge des Veranstaltungstags. */
    private const TIMES = [
        'get_in' => 'Get-in',
        'load_in' => 'Load-in',
        'admission' => 'Einlass',
        'vip_admission' => 'VIP-Einlass',
        'start_time' => 'Beginn',
        'end_time' => 'Ende',
        'curfew' => 'Curfew',
        'load_out' => 'Load-out',
    ];

    /** Planungsprüfungen aus der PHP-Version (dort „Hands geplant?“ usw.). */
    public const CHECKS = [
        'hands' => 'Hands',
        'traffic' => 'Verkehrsposten',
        'pvc_setup' => 'PVC Aufbau',
        'pvc_teardown' => 'PVC Abbau',
        'cleaning' => 'Reinigung',
        'interim_cleaning' => 'Zwischenreinigung',
        'special_cleaning' => 'Sonderreinigung',
        'bar_setup' => 'Tresen Aufbau',
        'bar_teardown' => 'Tresen Abbau',
        'chairs_ordered' => 'Stühle bestellt',
        'merch_fee_check' => 'Merch-Fee eingesammelt',
    ];

    public const CHECK_OPTIONS = ['yes' => 'ja', 'no' => 'nein', 'na' => 'entfällt'];

    public static function configure(Schema $schema): Schema
    {
        // Neu anlegen wie in der PHP-Version: nur die Daten der Buchung. Den
        // Workspace mit Übersicht und Phasen gibt es, sobald das Event existiert.
        if ($schema->getOperation() === 'create') {
            return $schema
                ->columns(1)
                ->components([
                    Section::make('Daten')->columns(3)->schema(self::bookingData()),
                ]);
        }

        return $schema
            ->columns(1)
            ->components([
                Tabs::make('Phasen')
                    ->persistTabInQueryString('phase')
                    ->tabs([
                        Tab::make('Übersicht')
                            ->id('uebersicht')
                            ->icon(Heroicon::OutlinedHome)
                            ->schema([
                                View::make('filament.events.dashboard')
                                    ->viewData(fn (Event $record, $livewire): array => [
                                        'event' => $record,
                                        'pageUrl' => $livewire::getUrl(['record' => $record]),
                                    ]),
                                self::embedded(NotesRelationManager::class),
                            ]),
                        self::phase('Buchung', 'buchung')->schema([
                            self::sections(array_values(array_filter([
                                Tab::make('Daten')
                                    ->id('daten')
                                    ->schema([Section::make()->columns(3)->schema(self::bookingData())]),
                                // Nur mit Recht auf die Buchhaltung, bearbeiten nur mit Schreibrecht dort.
                                // Nicht bloß ausgeblendet: Filament füllt auch verborgene Abschnitte,
                                // die Finanzdaten stünden sonst im Seitenquelltext.
                                self::can(Area::Buchhaltung)
                                    ? Tab::make('Buchhaltung')->id('buchhaltung')->schema(self::accounting())
                                    : null,
                                Tab::make('PR')
                                    ->id('pr')
                                    ->schema([self::pr()]),
                            ]))),
                        ]),
                        self::phase('Planung', 'planung')->schema([
                            self::sections([
                                Tab::make('Zeiten')->id('zeiten')->schema([self::times()]),
                                Tab::make('Checkliste')->id('checkliste')->schema([self::checklist()]),
                                Tab::make('Bühne')->id('buehne')->schema([
                                    Actions::make([
                                        Action::make('stagePlan')
                                            ->label('Bühnenplan anzeigen')
                                            ->icon(Heroicon::OutlinedMap)
                                            ->url(fn (Event $record): string => EventResource::getUrl('stage-plan', ['record' => $record])),
                                    ])->alignEnd(),
                                    self::stage(),
                                ]),
                                Tab::make('Personal')->id('personal')->schema([
                                    Section::make()
                                        ->columns(2)
                                        ->schema(AssignmentFields::all(except: [AssignmentRole::ProjectLead])),
                                ]),
                                Tab::make('Gewerke')->id('gewerke')->schema([
                                    Section::make()
                                        ->description('Wer die Leistung stellt, welches Gewerk oder welcher Anbieter. Leer heißt: nicht festgelegt.')
                                        ->schema(ServiceFields::all()),
                                ]),
                                Tab::make('Gästeliste')->id('gaeste')->schema([self::embedded(GuestsRelationManager::class)]),
                                Tab::make('Sonstiges')->id('sonstiges')->schema([self::other()]),
                            ]),
                        ]),
                        // In der PHP-Version dazu Übergabe, Bestellscheine, Checklisten, Schäden – noch nicht portiert
                        self::phase('Durchführung', 'durchfuehrung')->schema(self::execution()),
                    ]),
            ]);
    }

    /** Phase mit ihrem Fortschritt als Zahl am Reiter. */
    private static function phase(string $label, string $id): Tab
    {
        return Tab::make($label)
            ->id($id)
            ->badge(fn (?Event $record): ?string => $record ? EventProgress::phases($record)[$id] . ' %' : null);
    }

    /** @param  list<Tab>  $tabs  Unterbereiche einer Phase */
    private static function sections(array $tabs): Tabs
    {
        return Tabs::make('Bereiche')
            ->persistTabInQueryString('bereich')
            ->contained(false)
            ->tabs($tabs);
    }

    /** Relation Manager als Teil eines Reiters, in der Detailansicht nur lesend. */
    private static function embedded(string $relationManager): Livewire
    {
        return Livewire::make($relationManager, fn (Event $record, $livewire): array => [
            'ownerRecord' => $record,
            'pageClass' => $livewire::class,
        ]);
    }

    /** Buchung › Daten wie in der PHP-Version; auch das Formular zum Anlegen. */
    private static function bookingData(): array
    {
        return [
            TextInput::make('title')
                ->label('Titel')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            DateTimePicker::make('starts_at')
                ->label('Beginn')
                ->required()
                ->seconds(false),
            DateTimePicker::make('ends_at')
                ->label('Ende')
                ->seconds(false)
                ->afterOrEqual('starts_at'),
            Select::make('status')
                ->label('VA-Status')
                ->options(fn (?Event $record): array => OptionChoices::for(OptionField::VaStatus, $record?->status))
                ->native(false),
            Select::make('promoter_id')
                ->label('Veranstalter')
                ->relationship('promoter', 'name', fn ($query) => $query->where('is_archived', false)->orderBy('name'))
                ->searchable()
                ->preload(),
            AssignmentFields::select(AssignmentRole::ProjectLead),
            Toggle::make('closed')
                ->label('Event abgeschlossen')
                ->inline(false)
                ->hiddenOn('create'),
            Select::make('event_type1')
                ->label('Kategorie 1')
                ->options(fn (?Event $record): array => OptionChoices::for(OptionField::VaType1, $record?->event_type1))
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('event_type2', null))
                ->native(false),
            Select::make('event_type2')
                ->label('Kategorie 2')
                ->options(fn (Get $get, ?Event $record): array => OptionChoices::for(OptionField::VaType2, $record?->event_type2, $get('event_type1')))
                ->native(false),
            Select::make('ticketing')
                ->label('Ticketing')
                ->options(fn (?Event $record): array => OptionChoices::for(OptionField::Ticketing, $record?->ticketing))
                ->native(false),
            TextInput::make('va_id')
                ->label('VA-ID')
                ->disabled()
                ->dehydrated(false)
                ->placeholder('wird beim Anlegen vergeben'),
            TextInput::make('va_nr')
                ->label('Laufende Nummer')
                ->disabled()
                ->dehydrated(false)
                ->placeholder('wird beim Anlegen vergeben'),
            TextInput::make('pax_expected')
                ->label('PAX erwartet')
                ->numeric()
                ->minValue(0),
            CheckboxList::make('areas')
                ->label('Bereiche')
                ->options(fn (?Event $record): array => OptionChoices::for(OptionField::Areas, $record?->areas)),
            CheckboxList::make('seating')
                ->label('Bestuhlung')
                ->options(fn (?Event $record): array => OptionChoices::for(OptionField::Seating, $record?->seating)),
            Textarea::make('booking_notes')
                ->label('Interne Buchungsnotizen')
                ->rows(3)
                ->columnSpanFull(),
        ];
    }

    /**
     * Buchung › Buchhaltung. In Abschnitten mit relationship() ist $record das
     * zugehörige Modell, nicht das Event.
     *
     * @return list<Section>
     */
    private static function accounting(): array
    {
        return [
            Section::make('Finanzen')
                ->relationship('finance')
                ->columns(2)
                ->disabled(fn (): bool => !self::can(Area::Buchhaltung, Level::Edit))
                ->schema([
                    Select::make('contract_status')
                        ->label('Vertragsstatus')
                        ->options(fn (?EventFinance $record): array => OptionChoices::for(OptionField::ContractStatus, $record?->contract_status))
                        ->native(false),
                    Select::make('price_list')
                        ->label('Preisliste')
                        ->options(fn (?EventFinance $record): array => OptionChoices::for(OptionField::PriceList, $record?->price_list))
                        ->native(false),
                    CheckboxList::make('accounting_status')
                        ->label('FIBU-Status')
                        ->options(fn (?EventFinance $record): array => OptionChoices::for(OptionField::AccountingStatus, $record?->accounting_status))
                        ->columns(2)
                        ->columnSpanFull(),
                    TextInput::make('rent')
                        ->label('Miete')
                        ->numeric()
                        ->suffix('€'),
                    TagsInput::make('invoice_numbers')
                        ->label('Rechnungsnummern'),
                    Toggle::make('accounting_closed')
                        ->label('Abrechnung abgeschlossen'),
                ]),
            Section::make('Eingangsrechnungen')
                ->description('Welche Rechnungen von Dienstleistern erwartet werden und ob sie da sind. Mobiliar ist bei „bestuhlt“ automatisch erwartet, Cobra – Haus-Delay bei Haus-Delay.')
                ->columns(3)
                ->disabled(fn (): bool => !self::can(Area::Buchhaltung, Level::Edit))
                ->schema(IncomingInvoiceFields::all()),
        ];
    }

    private static function pr(): Section
    {
        return Section::make()
            ->relationship('pr')
            ->columns(2)
            ->schema([
                DatePicker::make('pr_date')
                    ->label('PR-Datum'),
                Select::make('pr_status')
                    ->label('PR-Status')
                    ->options(fn (?EventPr $record): array => OptionChoices::for(OptionField::PrStatus, $record?->pr_status))
                    ->native(false),
            ]);
    }

    private static function times(): Section
    {
        return Section::make()
            ->relationship('schedule')
            ->columns(4)
            ->schema(array_map(
                fn (string $field, string $label): TimePicker => TimePicker::make($field)->label($label)->seconds(false),
                array_keys(self::TIMES),
                self::TIMES,
            ));
    }

    private static function checklist(): Section
    {
        return Section::make()
            ->relationship('checklist')
            ->columns(3)
            ->schema([
                ...array_map(
                    fn (string $field, string $label): ToggleButtons => ToggleButtons::make($field)
                        ->label($label)
                        ->options(self::CHECK_OPTIONS)
                        ->colors(['yes' => 'success', 'no' => 'danger', 'na' => 'gray'])
                        ->grouped(),
                    array_keys(self::CHECKS),
                    self::CHECKS,
                ),
                TextInput::make('merch_fee')
                    ->label('Merch-Fee')
                    ->maxLength(120),
                Toggle::make('power_ant')
                    ->label('Miete Elektro-Ameise'),
                Toggle::make('house_rig_early')
                    ->label('Haus-Rig ab 7 Uhr'),
                Toggle::make('briefing_complete')
                    ->label('Briefing vollständig'),
            ]);
    }

    /** Planung › Sonstiges: in der PHP-Version WLAN; dazu, was keinen eigenen Platz hat. */
    private static function other(): Section
    {
        return Section::make()
            ->columns(3)
            ->schema([
                TextInput::make('wlan')
                    ->label('WLAN')
                    ->maxLength(120),
                // Wie in der PHP-Version nur für Bearbeiter (siehe auch ViewEvent)
                TextInput::make('wlan_password')
                    ->label('WLAN-Passwort')
                    ->maxLength(120)
                    ->visible(fn (): bool => self::can(Area::Events, Level::Edit)),
                TextInput::make('onsite_contact')
                    ->label('Ansprechpartner vor Ort')
                    ->maxLength(120),
                Textarea::make('description')
                    ->label('Beschreibung')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    /** Aktive Räume plus die schon zugeordneten, falls einer inzwischen inaktiv ist. */
    private static function roomField(string $relationship, string $label): CheckboxList
    {
        return CheckboxList::make($relationship)
            ->label($label)
            ->relationship(
                name: $relationship,
                titleAttribute: 'name',
                modifyQueryUsing: fn (Builder $query, ?Event $record): Builder => $query
                    ->where(fn (Builder $q): Builder => $q
                        ->where('rooms.is_active', true)
                        ->orWhereIn('rooms.id', $record?->{$relationship}->pluck('id')->all() ?? []))
                    ->orderBy('rooms.sort_order'),
            )
            ->columns(2);
    }

    /**
     * Durchführung › Betrieb (Show Day) wie in der PHP-Version.
     *
     * @return list<Section>
     */
    private static function execution(): array
    {
        return [
            Section::make('Räume')
                ->columns(2)
                ->schema([
                    self::roomField('backstageRooms', 'Backstage'),
                    self::roomField('officeRooms', 'Büros'),
                ]),
            Section::make('Betrieb')
                ->relationship('operation')
                ->columns(3)
                ->schema([
                    TextInput::make('power_consumption')
                        ->label('Stromverbrauch (kWh)')
                        ->numeric(),
                    Toggle::make('bus_power')
                        ->label('Buspower'),
                    Toggle::make('house_delay')
                        ->label('Haus-Delay'),
                ]),
            Section::make('Abschluss')
                ->columns(3)
                ->schema([
                    TextInput::make('pax')
                        ->label('PAX abgerechnet')
                        ->numeric()
                        ->minValue(0),
                    self::soldOutAward(),
                ]),
        ];
    }

    /**
     * Steht in der Bühnen-Tabelle, gehört aber in die Durchführung. Ein zweiter
     * Abschnitt auf derselben Beziehung würde bei neuen Events die Zeile doppelt
     * anlegen – daher ein Feld ohne Bindung, gespeichert nach dem Abschnitt
     * „Bühne“. Ja/nein/leer wie in der PHP-Version, leer heißt: nicht erfasst.
     */
    private static function soldOutAward(): ToggleButtons
    {
        return ToggleButtons::make('sold_out_award')
            ->label('Sold-Out-Award')
            ->boolean('ja', 'nein')
            ->grouped()
            // Als 1/0 wie die Schaltflächen: Filament wendet seine Umwandlung vor
            // diesem Aufruf an, ein true bliebe stehen und nichts wäre markiert.
            ->afterStateHydrated(fn (ToggleButtons $component, ?Event $record) => $component->state(
                $record?->stage?->sold_out_award === null ? null : (int) $record->stage->sold_out_award,
            ))
            ->dehydrated(false)
            ->saveRelationshipsUsing(fn (Event $record, mixed $state) => $record->stage()->updateOrCreate(
                [],
                ['sold_out_award' => $state === null || $state === '' ? null : (bool) $state],
            ));
    }

    private static function can(Area $area, Level $minimum = Level::Read): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can($area, $minimum);
    }

    /** Bühnenmaße wie im Bühnen-Formular der PHP-Version, mit Podest-Rechnung. */
    private static function stage(): Section
    {
        return Section::make()
            ->relationship('stage')
            ->columns(4)
            ->schema([
                Fieldset::make('Hauptbühne')
                    ->columns(3)
                    ->schema([
                        self::meterField('width', 'Breite'),
                        self::meterField('depth', 'Tiefe'),
                        Select::make('height')
                            ->label('Höhe')
                            ->options(fn (?EventStage $record): array => self::heightOptions($record?->height))
                            ->native(false),
                    ]),
                Fieldset::make('Wing stage left (SL)')
                    ->columns(3)
                    ->schema([
                        self::meterField('wing_sl_width', 'Breite'),
                        self::meterField('wing_sl_depth', 'Tiefe'),
                        self::offsetField('wing_sl_offset')
                            ->helperText('Abstand zur Bühnen-Vorderkante, Richtung Upstage'),
                    ]),
                Fieldset::make('Wing stage right (SR)')
                    ->columns(3)
                    ->schema([
                        self::meterField('wing_sr_width', 'Breite'),
                        self::meterField('wing_sr_depth', 'Tiefe'),
                        self::offsetField('wing_sr_offset'),
                    ]),
                Fieldset::make('Rollipodest')
                    ->columns(2)
                    ->schema([
                        self::meterField('rollpodest_width', 'Breite')
                            ->placeholder((string) StagePodests::DEFAULT_ROLL_WIDTH),
                        self::meterField('rollpodest_depth', 'Tiefe')
                            ->placeholder((string) StagePodests::DEFAULT_ROLL_DEPTH),
                    ]),
                TextInput::make('extra_platforms')
                    ->label('Sonstige Podeste')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(999)
                    ->suffix('Stück')
                    ->live(onBlur: true),
                TextEntry::make('podest_calculation')
                    ->label('Podeste')
                    ->state(fn (Get $get): string => self::podestText(self::podests($get)))
                    ->color(fn (Get $get): ?string => self::podests($get)['total'] > StagePodests::inventory() ? 'danger' : null)
                    ->columnSpan(3),
                Textarea::make('stage_notes')
                    ->label('Anmerkungen Bühne')
                    ->rows(2)
                    ->columnSpanFull(),
                // Altdaten aus AppSheet, die das Formular der PHP-Version nicht mehr zeigt.
                TextEntry::make('legacy_notes')
                    ->label('Anmerkung aus AppSheet')
                    ->state(fn (?EventStage $record): ?string => $record?->notes)
                    ->visible(fn (?EventStage $record): bool => filled($record?->notes))
                    ->columnSpan(2),
                TextEntry::make('legacy_other')
                    ->label('Sonstige Podeste laut AppSheet')
                    ->state(fn (?EventStage $record): ?string => $record?->other_info)
                    ->visible(fn (?EventStage $record): bool => filled($record?->other_info))
                    ->columnSpan(2),
            ]);
    }

    /** Ganze Meter; gespeicherte Dezimalwerte („14.00“) erscheinen als „14“. */
    private static function meterField(string $name, string $label, bool $live = true): TextInput
    {
        $field = TextInput::make($name)
            ->label($label)
            ->integer()
            ->minValue(0)
            ->maxValue(99)
            ->suffix('m')
            ->formatStateUsing(fn (mixed $state): ?int => $state === null || $state === '' ? null : (int) round((float) $state));

        return $live ? $field->live(onBlur: true) : $field;
    }

    /** Versatz der Wings; leer heißt wie in der PHP-Version 1 m. */
    private static function offsetField(string $name): TextInput
    {
        return self::meterField($name, 'Versatz', live: false)
            ->dehydrateStateUsing(fn (mixed $state): int => $state === null || $state === '' ? StagePlan::DEFAULT_WING_OFFSET : (int) $state);
    }

    /** @return array<string, string> Schlüssel im Format der Datenbank („1.40“) */
    private static function heightOptions(mixed $current): array
    {
        $options = [];
        foreach (StagePodests::HEIGHTS as $height) {
            $options[number_format($height, 2, '.', '')] = StagePodests::formatMeters($height) . ' m';
        }
        if ($current !== null && $current !== '' && !isset($options[number_format((float) $current, 2, '.', '')])) {
            $options[number_format((float) $current, 2, '.', '')] = StagePodests::formatMeters((float) $current) . ' m (Altwert)';
        }

        return $options;
    }

    /** @return array{main: int, wing_sl: int, wing_sr: int, rollpodest: int, other: int, total: int} */
    private static function podests(Get $get): array
    {
        return StagePodests::calculate(array_combine(
            EventStage::PODEST_FIELDS,
            array_map(fn (string $field): mixed => $get($field), EventStage::PODEST_FIELDS),
        ));
    }

    /** @param  array{main: int, wing_sl: int, wing_sr: int, rollpodest: int, other: int, total: int}  $podests */
    private static function podestText(array $podests): string
    {
        $inventory = StagePodests::inventory();
        $text = "Hauptbühne {$podests['main']} + Wing SL {$podests['wing_sl']} + Wing SR {$podests['wing_sr']}"
            . " + Rollipodest {$podests['rollpodest']} + sonstige {$podests['other']} = {$podests['total']}";

        return $podests['total'] > $inventory
            ? $text . ' – ' . ($podests['total'] - $inventory) . " über dem Bestand von {$inventory}, Nachbestellung nötig"
            : $text . " – im Bestand von {$inventory}";
    }
}
