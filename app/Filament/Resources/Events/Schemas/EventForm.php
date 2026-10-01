<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Access\Area;
use App\Access\Level;
use App\Enums\AssignmentRole;
use App\Enums\OptionField;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\RelationManagers\FilesRelationManager;
use App\Filament\Resources\Events\RelationManagers\GuestsRelationManager;
use App\Filament\Resources\Events\RelationManagers\DamagesRelationManager;
use App\Filament\Resources\Events\RelationManagers\HandoverProtocolsRelationManager;
use App\Filament\Resources\Events\RelationManagers\OrderSlipsRelationManager;
use App\Filament\Resources\Events\RelationManagers\ShowChecklistsRelationManager;
use App\Filament\Resources\Events\RelationManagers\NotesRelationManager;
use App\Filament\Support\AssignmentFields;
use App\Filament\Support\IncomingInvoiceFields;
use App\Filament\Support\OptionChoices;
use App\Filament\Support\RoomUsageFields;
use App\Filament\Support\ServiceFields;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Event;
use App\Models\EventFinance;
use App\Models\EventOperation;
use App\Models\EventPr;
use App\Models\EventStage;
use App\Support\EventProgress;
use Closure;
use App\Support\StagePlan;
use App\Support\StagePodests;
use App\Support\StageSettings;
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
use Filament\Schemas\Components\Grid;
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
use Illuminate\Support\HtmlString;

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
                    ->extraAttributes(['class' => 'vc-phases'])
                    ->tabs([
                        Tab::make(self::stepLabel('⌂', 'Übersicht', 'Dashboard & Fortschritt'))
                            ->id('uebersicht')
                            ->schema([
                                View::make('filament.events.dashboard')
                                    ->viewData(fn (Event $record, $livewire): array => [
                                        'event' => $record,
                                        'pageUrl' => $livewire::getUrl(['record' => $record]),
                                    ]),
                                self::embedded(NotesRelationManager::class),
                            ]),
                        self::phase('Buchung', 'buchung')->schema([
                            self::sections('buchung', array_values(array_filter([
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
                            self::sections('planung', [
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
                                Tab::make('Dateien')->id('dateien')->schema([
                                    self::embedded(FilesRelationManager::class),
                                    // Übergreifende Dateien hängen an mehreren Events, gepflegt werden sie zentral
                                    View::make('filament.events.linked-files')
                                        ->viewData(fn (Event $record): array => ['files' => $record->linkedFiles()->with('tag')->get()])
                                        ->visible(fn (Event $record): bool => $record->linkedFiles()->exists()),
                                ]),
                                Tab::make('Sonstiges')->id('sonstiges')->schema([self::other()]),
                            ]),
                        ]),
                        self::phase('Durchführung', 'durchfuehrung')->schema([
                            self::sections('durchfuehrung', [
                                Tab::make('Betrieb')->id('betrieb')->schema(self::execution()),
                                Tab::make('Übergabeprotokolle')->id('uebergabe')->schema([self::embedded(HandoverProtocolsRelationManager::class)]),
                                Tab::make('Bestellscheine')->id('bestellscheine')->schema([self::embedded(OrderSlipsRelationManager::class)]),
                                Tab::make('Checklisten')->id('checklisten')->schema([self::embedded(ShowChecklistsRelationManager::class)]),
                                Tab::make('Schäden')->id('schaeden')->schema([self::embedded(DamagesRelationManager::class)]),
                            ]),
                        ]),
                    ]),
            ]);
    }

    /** Beschreibung der Phasen wie im Phasen-Stepper der PHP-Version. */
    private const PHASES = [
        'buchung' => ['1', 'Buchung', 'Event anlegen und vertragliche Stammdaten'],
        'planung' => ['2', 'Planung', 'Advancing, Zeiten, Personal und Gewerke'],
        'durchfuehrung' => ['3', 'Durchführung', 'Show Day – Betrieb und Räume'],
    ];

    /** Phase mit Nummer, Beschreibung und Fortschritt am Reiter. */
    private static function phase(string $label, string $id): Tab
    {
        [$number, , $description] = self::PHASES[$id];

        return Tab::make(self::stepLabel($number, $label, $description))
            ->id($id)
            ->badge(fn (?Event $record): ?string => $record ? EventProgress::phases($record)[$id] . ' %' : null);
    }

    /** Reiterbeschriftung als Schritt: Nummer, Titel, Beschreibung (Stil: .vc-phases). */
    private static function stepLabel(string $number, string $title, string $description): HtmlString
    {
        return new HtmlString(sprintf(
            '<span class="vc-step__num">%s</span><span class="vc-step__text"><span class="vc-step__title">%s</span><span class="vc-step__desc">%s</span></span>',
            e($number),
            e($title),
            e($description),
        ));
    }

    /** @param  list<Tab>  $tabs  Unterbereiche einer Phase, in der Farbe der Phase */
    private static function sections(string $phase, array $tabs): Tabs
    {
        return Tabs::make('Bereiche')
            ->persistTabInQueryString('bereich')
            ->contained(false)
            ->extraAttributes(['class' => "vc-sections vc-sections--{$phase}"])
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

    /**
     * Durchführung › Betrieb (Show Day) wie in der PHP-Version: Räume, Strom und
     * Bus-Strom, die Ja/Nein-Punkte des Tages, PAX.
     *
     * @return list<Section>
     */
    private static function execution(): array
    {
        return [
            Section::make('Räume')
                ->description('Jeder Raum einmal: Backstage, Büro oder neutral (nicht belegt).')
                ->columns(['default' => 1, 'lg' => 2, 'xl' => 3])
                ->schema(RoomUsageFields::all()),
            // Vor dem Abschnitt „Check“: legt bei neuen Events die Zeile an, die Haus-Delay dort nur ändert
            Section::make('Strom')
                ->relationship('operation')
                ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                ->schema([
                    ToggleButtons::make('bus_power')
                        ->label('Bus-Strom')
                        ->helperText('Anschlüsse, werden einzeln abgerechnet')
                        ->options(array_combine(range(0, EventOperation::MAX_BUS_POWER), array_map('strval', range(0, EventOperation::MAX_BUS_POWER))))
                        ->grouped(),
                    self::meterReading('power_meter_start', 'Stromzähler Stand Anfang'),
                    self::meterReading('power_meter_end', 'Stromzähler Stand Ende')
                        ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $start = $get('power_meter_start');
                            if (filled($value) && filled($start) && (float) $value < (float) $start) {
                                $fail('Der Stand am Ende kann nicht kleiner sein als am Anfang.');
                            }
                        }),
                    TextEntry::make('power_consumption_view')
                        ->label('Verbrauch')
                        ->state(fn (Get $get, ?EventOperation $record): string => self::consumptionText($get, $record)),
                ]),
            Section::make('Check')
                ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                ->schema([
                    self::yesNo('checklist', 'special_cleaning', 'Sonderreinigung', textValues: true),
                    self::yesNo('operation', 'house_delay', 'Haus-Delay'),
                    self::yesNo('checklist', 'power_ant', 'Miete Elektro-Ameise'),
                    self::yesNo('checklist', 'house_rig_early', 'Haus-Rig ab 7 Uhr'),
                    self::yesNo('stage', 'sold_out_award', 'Sold-Out-Award'),
                    TextInput::make('pax')
                        ->label('PAX abgerechnet')
                        ->numeric()
                        ->minValue(0),
                ]),
        ];
    }

    private static function meterReading(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->step(0.01)
            ->suffix('kWh')
            ->live(onBlur: true);
    }

    /** Verbrauch aus den Zählerständen; sonst ein früher eingetragener Wert. */
    private static function consumptionText(Get $get, ?EventOperation $record): string
    {
        $start = $get('power_meter_start');
        $end = $get('power_meter_end');
        if (filled($start) && filled($end) && is_numeric($start) && is_numeric($end)) {
            return number_format((float) $end - (float) $start, 2, ',', '.') . ' kWh';
        }
        if ($record?->power_consumption !== null) {
            return number_format($record->power_consumption, 0, ',', '.') . ' kWh (eingetragen)';
        }

        return 'aus den Zählerständen';
    }

    /**
     * Ja/nein für Werte aus anderen 1:1-Tabellen (Checkliste, Betrieb, Bühne).
     * Ohne Bindung gespeichert per updateOrCreate – ein zweiter Abschnitt auf
     * derselben Beziehung legte bei neuen Events die Zeile doppelt an. Leer
     * heißt: nicht erfasst. Textwerte wie in der Checkliste („yes“/„no“), aber
     * nur ja/nein (Wunsch vom 01.10.2026).
     */
    private static function yesNo(string $relation, string $column, string $label, bool $textValues = false): ToggleButtons
    {
        $field = ToggleButtons::make($column)
            ->label($label)
            ->grouped()
            ->dehydrated(false);
        $field = $textValues
            ? $field->options(['yes' => 'ja', 'no' => 'nein'])
                ->colors(['yes' => 'success', 'no' => 'danger'])
                ->icons(['yes' => Heroicon::Check, 'no' => Heroicon::XMark])
            : $field->boolean('ja', 'nein');

        return $field
            // Für Ja/Nein als 1/0 wie die Schaltflächen: Filament wandelt vor diesem Aufruf um.
            ->afterStateHydrated(function (ToggleButtons $component, ?Event $record) use ($relation, $column, $textValues): void {
                $value = $record?->{$relation}?->{$column};
                $component->state(match (true) {
                    $value === null => null,
                    $textValues => in_array($value, ['yes', 'no'], true) ? $value : null, // „entfällt“ gibt es hier nicht
                    default => (int) $value,
                });
            })
            ->saveRelationshipsUsing(fn (Event $record, mixed $state) => $record->{$relation}()->updateOrCreate([], [
                $column => match (true) {
                    $state === null || $state === '' => null,
                    $textValues => (string) $state,
                    default => (bool) $state,
                },
            ]));
    }

    private static function can(Area $area, Level $minimum = Level::Read): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can($area, $minimum);
    }

    /**
     * Bühnenmaße wie im Bühnen-Formular der PHP-Version: vier gleich gebaute
     * Kästen (je drei Felder), darunter die Podest-Rechnung als Zusammenfassung.
     */
    private static function stage(): Section
    {
        return Section::make()
            ->relationship('stage')
            ->schema([
                Grid::make(['default' => 1, 'lg' => 2])
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
                        Fieldset::make('Rollipodest und sonstige Podeste')
                            ->columns(3)
                            ->schema([
                                self::meterField('rollpodest_width', 'Breite')
                                    ->placeholder(fn (): string => (string) StageSettings::current()->rollWidth),
                                self::meterField('rollpodest_depth', 'Tiefe')
                                    ->placeholder(fn (): string => (string) StageSettings::current()->rollDepth),
                                TextInput::make('extra_platforms')
                                    ->label('Sonstige')
                                    ->integer()
                                    ->minValue(0)
                                    ->maxValue(999)
                                    ->suffix('Stück')
                                    ->live(onBlur: true),
                            ]),
                        Fieldset::make('Wing stage left (SL)')
                            ->columns(3)
                            ->schema([
                                self::meterField('wing_sl_width', 'Breite'),
                                self::meterField('wing_sl_depth', 'Tiefe'),
                                self::offsetField('wing_sl_offset'),
                            ]),
                        Fieldset::make('Wing stage right (SR)')
                            ->columns(3)
                            ->schema([
                                self::meterField('wing_sr_width', 'Breite'),
                                self::meterField('wing_sr_depth', 'Tiefe'),
                                self::offsetField('wing_sr_offset'),
                            ]),
                    ]),
                View::make('filament.events.podest-summary')
                    ->viewData(fn (Get $get): array => [
                        'podests' => self::podests($get),
                        'inventory' => StagePodests::inventory(),
                    ]),
                Textarea::make('stage_notes')
                    ->label('Anmerkungen Bühne')
                    ->helperText('Erscheint kursiv im Bühnenplan.')
                    ->rows(2),
                // Altdaten aus AppSheet, die das Formular der PHP-Version nicht mehr zeigt.
                // „sonst. Podeste“ als reine Zahl übernimmt der Import in „Sonstige“.
                Grid::make(['default' => 1, 'lg' => 2])
                    ->schema([
                        TextEntry::make('legacy_notes')
                            ->label('Anmerkung aus AppSheet')
                            ->state(fn (?EventStage $record): ?string => $record?->notes)
                            ->visible(fn (?EventStage $record): bool => filled($record?->notes)),
                        TextEntry::make('legacy_other')
                            ->label('Sonstige Podeste laut AppSheet')
                            ->state(fn (?EventStage $record): ?string => $record?->other_info)
                            ->visible(fn (?EventStage $record): bool => filled($record?->other_info)),
                    ]),
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
            ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'Abstand zur Bühnen-Vorderkante, Richtung Upstage (weg vom Publikum)')
            ->dehydrateStateUsing(fn (mixed $state): int => $state === null || $state === '' ? StagePlan::DEFAULT_WING_OFFSET : (int) $state);
    }

    /** @return array<string, string> Schlüssel im Format der Datenbank („1.40“) */
    private static function heightOptions(mixed $current): array
    {
        $options = [];
        foreach (StagePodests::heights() as $height) {
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
}
