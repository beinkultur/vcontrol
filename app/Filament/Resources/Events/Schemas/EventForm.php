<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Access\Area;
use App\Access\Level;
use App\Access\Module;
use App\Enums\AssignmentRole;
use App\Enums\OptionField;
use App\Filament\Resources\Costings\CostingResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\CostingsRelationManager;
use App\Filament\Resources\Events\RelationManagers\FilesRelationManager;
use App\Filament\Resources\Events\RelationManagers\GuestsRelationManager;
use App\Filament\Resources\Events\RelationManagers\DamagesRelationManager;
use App\Filament\Resources\Events\RelationManagers\DaysheetsRelationManager;
use App\Filament\Resources\Events\RelationManagers\HandoverProtocolsRelationManager;
use App\Filament\Resources\Events\RelationManagers\OrderSlipsRelationManager;
use App\Filament\Resources\Events\RelationManagers\ShowChecklistsRelationManager;
use App\Filament\Resources\Events\RelationManagers\NotesRelationManager;
use App\Filament\Support\AssignmentFields;
use App\Filament\Support\IncomingInvoiceFields;
use App\Filament\Support\MeterFields;
use App\Filament\Support\OptionChoices;
use App\Filament\Support\RoomUsageFields;
use App\Filament\Support\SeatingPlanFields;
use App\Filament\Support\ServiceFields;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Event;
use App\Models\EventFinance;
use App\Models\EventOperation;
use App\Models\EventPr;
use App\Models\EventStage;
use App\Models\PriceList;
use App\Support\EventProgress;
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
use Filament\Notifications\Notification;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Livewire\Component;

/**
 * Event-Workspace wie in der PHP-Version: Übersicht (Dashboard) und die drei
 * Phasen, darin die Unterbereiche als eigene Reiter. Reiter stehen in der URL
 * (?phase=planung&bereich=buehne), damit das Dashboard direkt hineinspringt.
 */
class EventForm
{
    /** Zeiten in der Reihenfolge des Veranstaltungstags. */
    public const TIMES = [
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
    ];

    public const CHECK_OPTIONS = ['yes' => 'ja', 'no' => 'nein', 'na' => 'entfällt'];

    public static function configure(Schema $schema): Schema
    {
        // Neu anlegen wie in der PHP-Version: nur die Daten der Buchung. Den
        // Workspace mit Übersicht und Phasen gibt es, sobald das Event existiert.
        if ($schema->getOperation() === 'create') {
            return $schema
                ->columns(1)
                ->components(self::bookingData());
        }

        return $schema
            ->columns(1)
            ->components([
                Tabs::make('Phasen')
                    ->persistTabInQueryString('phase')
                    ->extraAttributes(['class' => 'vc-phases'])
                    ->tabs([
                        self::tab(self::stepLabel('⌂', 'Übersicht', 'Dashboard & Fortschritt'), 'uebersicht')
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
                                self::tab('Daten', 'daten')->schema(self::bookingData()),
                                // Nur mit Recht auf die Buchhaltung, bearbeiten nur mit Schreibrecht dort.
                                // Nicht bloß ausgeblendet: Filament füllt auch verborgene Abschnitte,
                                // die Finanzdaten stünden sonst im Seitenquelltext.
                                self::can(Area::Buchhaltung)
                                    ? self::tab('Buchhaltung', 'buchhaltung')->schema(self::accounting())
                                    : null,
                                // Nur mit dem Modul Preislisten & Costing, für die Rollen unter Verwaltung › Venue (CostingSettings)
                                CostingResource::canAccess()
                                    ? self::tab('Costing', 'costing')->schema([self::embedded(CostingsRelationManager::class)])
                                    : null,
                                self::tab('PR', 'pr')
                                    ->schema([self::pr()]),
                            ]))),
                        ]),
                        self::phase('Planung', 'planung')->schema([
                            self::sections('planung', array_values(array_filter([
                                self::tab('Zeiten', 'zeiten')->schema([self::times()]),
                                self::tab('Checkliste', 'checkliste')->schema([self::checklist()]),
                                // Nur mit dem Modul Bühnenplan (Verwaltung › System)
                                Module::StagePlan->isEnabled()
                                    ? self::tab('Bühne', 'buehne')->schema([
                                        Actions::make([
                                            Action::make('stagePlan')
                                                ->label('Bühnenplan anzeigen')
                                                ->icon(Heroicon::OutlinedMap)
                                                // Erst speichern, damit der Plan die eben eingetragenen Maße zeigt.
                                                // Scheitert die Prüfung, bleibt man im Formular und sieht den Fehler.
                                                // In der Lese-Ansicht (ViewEvent) gibt es nichts zu speichern.
                                                ->action(function (Component $livewire, Event $record): void {
                                                    if ($livewire instanceof EditEvent) {
                                                        $livewire->save(shouldRedirect: false, shouldSendSavedNotification: false);
                                                    }
                                                    $livewire->redirect(EventResource::getUrl('stage-plan', ['record' => $record]), navigate: true);
                                                }),
                                        ])->key('stagePlanActions')->alignEnd(),
                                        self::stage(),
                                    ])
                                    : null,
                                self::tab('Personal', 'personal')->schema([
                                    Section::make()
                                        ->columns(2)
                                        ->schema(AssignmentFields::all(except: [AssignmentRole::ProjectLead])),
                                ]),
                                self::tab('Gewerke', 'gewerke')->schema([
                                    Section::make()
                                        ->description('Wer die Leistung stellt, welches Gewerk oder welcher Anbieter. Leer heißt: nicht festgelegt.')
                                        ->schema(ServiceFields::all()),
                                ]),
                                // Nur mit dem Modul Gästeliste (Verwaltung › System)
                                Module::GuestList->isEnabled()
                                    ? self::tab('Gästeliste', 'gaeste')->schema([self::embedded(GuestsRelationManager::class)])
                                    : null,
                                self::tab('Dateien', 'dateien')->schema([
                                    self::embedded(FilesRelationManager::class),
                                    // Übergreifende Dateien hängen an mehreren Events, gepflegt werden sie zentral
                                    View::make('filament.events.linked-files')
                                        ->viewData(fn (Event $record): array => ['files' => $record->linkedFiles()->with('tag')->get()])
                                        ->visible(fn (Event $record): bool => $record->linkedFiles()->exists()),
                                ]),
                                self::tab('Sonstiges', 'sonstiges')->schema([self::other()]),
                            ]))),
                        ]),
                        self::phase('Durchführung', 'durchfuehrung')->schema([
                            self::sections('durchfuehrung', [
                                self::tab('Betrieb', 'betrieb')->schema(self::execution()),
                                self::tab('Übergabeprotokolle', 'uebergabe')->schema([self::embedded(HandoverProtocolsRelationManager::class)]),
                                self::tab('Bestellscheine', 'bestellscheine')->schema([self::embedded(OrderSlipsRelationManager::class)]),
                                self::tab('Checklisten', 'checklisten')->schema([self::embedded(ShowChecklistsRelationManager::class)]),
                                self::tab('Schäden', 'schaeden')->schema([self::embedded(DamagesRelationManager::class)]),
                                self::tab('Daysheets', 'daysheets')->schema([self::embedded(DaysheetsRelationManager::class)]),
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

        return self::tab(self::stepLabel($number, $label, $description), $id)
            ->badge(fn (?Event $record): ?string => $record ? EventProgress::phases($record)[$id] . ' %' : null);
    }

    /**
     * Reiter mit fester Kennung in der Adresse (?phase=planung&bereich=buehne).
     * Filament 5.9 liest die Adresse über id(), schreibt beim Klicken aber key()
     * hinein – ohne beides stünde danach ein Slug der Beschriftung in der Adresse.
     * Nicht vererbbar wie Filaments eigener Reiter-Schlüssel, sonst bekämen alle
     * Felder darunter ein Präfix im Schlüssel.
     */
    private static function tab(string|Htmlable $label, string $id): Tab
    {
        return Tab::make($label)->id($id)->key($id, isInheritable: false);
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

    /**
     * Render-Hook am Ende der Reiterzeile: Speichern/Abbrechen rechtsbündig
     * (filament.events.section-actions), nur auf EditEvent – registriert im
     * AppPanelProvider mit diesem Scope, die Lese-Ansicht bekommt keine.
     */
    public const SECTION_ACTIONS_HOOK = 'vc-event-section-actions';

    /** @param  list<Tab>  $tabs  Unterbereiche einer Phase, in der Farbe der Phase */
    private static function sections(string $phase, array $tabs): Tabs
    {
        return Tabs::make('Bereiche')
            ->persistTabInQueryString('bereich')
            ->contained(false)
            ->extraAttributes(['class' => "vc-sections vc-sections--{$phase}"])
            ->endRenderHooks([self::SECTION_ACTIONS_HOOK])
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

    /**
     * Buchung › Daten, auch das Formular zum Anlegen. Links, was das Event ist
     * und für wen (Veranstaltung) und wie es in der Venue läuft (Venue &
     * Publikum), rechts daneben Termin und Internes – auf dem Handy in dieser
     * Reihenfolge untereinander. VA-ID, laufende Nummer und „abgeschlossen“ gibt
     * es erst, wenn das Event existiert.
     *
     * @return list<Grid>
     */
    private static function bookingData(): array
    {
        return [
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make('Veranstaltung')
                    ->columnSpan(['lg' => 2])
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Titel')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('promoter_id')
                            ->label('Veranstalter')
                            ->relationship('promoter', 'name', fn ($query) => $query->where('is_archived', false)->orderBy('name'))
                            ->searchable()
                            ->preload(),
                        Select::make('status')
                            ->label('VA-Status')
                            ->options(fn (?Event $record): array => OptionChoices::for(OptionField::VaStatus, $record?->status))
                            ->native(false),
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
                    ]),
                Section::make('Termin')
                    ->schema([
                        DateTimePicker::make('starts_at')
                            ->label('Beginn')
                            ->required()
                            ->seconds(false),
                        DateTimePicker::make('ends_at')
                            ->label('Ende')
                            ->seconds(false)
                            ->afterOrEqual('starts_at'),
                    ]),
                Section::make('Venue & Publikum')
                    ->columnSpan(['lg' => 2])
                    ->columns(2)
                    ->schema([
                        TextInput::make('pax_expected')
                            ->label('PAX erwartet')
                            ->numeric()
                            ->minValue(0),
                        Select::make('ticketing')
                            ->label('Ticketing')
                            ->options(fn (?Event $record): array => OptionChoices::for(OptionField::Ticketing, $record?->ticketing))
                            ->native(false),
                        // Standard-Saalplan (übernimmt seine Bereiche) oder eigener PDF-Plan
                        ...SeatingPlanFields::forEvent(),
                        CheckboxList::make('areas')
                            ->label('Bereiche')
                            ->options(fn (?Event $record): array => OptionChoices::for(OptionField::Areas, $record?->areas))
                            ->columns(2),
                        CheckboxList::make('seating')
                            ->label('Bestuhlung')
                            ->options(fn (?Event $record): array => OptionChoices::for(OptionField::Seating, $record?->seating)),
                        Actions::make([self::changeSeatingPlan()])
                            ->key('seatingPlanActions')
                            ->visible(fn (Component $livewire): bool => $livewire instanceof ViewEvent && SeatingPlanFields::canChange())
                            ->columnSpanFull(),
                    ]),
                Section::make('Intern')
                    ->schema([
                        AssignmentFields::select(AssignmentRole::ProjectLead),
                        // Nur mit dem Modul Preislisten & Costing
                        ...(Module::PriceLists->isEnabled() ? [self::priceList()] : []),
                        Grid::make(2)
                            ->hiddenOn('create')
                            ->schema([
                                TextInput::make('va_id')
                                    ->label('VA-ID')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('va_nr')
                                    ->label('Laufende Nummer')
                                    ->disabled()
                                    ->dehydrated(false),
                            ]),
                        Toggle::make('closed')
                            ->label('Event abgeschlossen')
                            ->hiddenOn('create'),
                        Textarea::make('booking_notes')
                            ->label('Interne Buchungsnotizen')
                            ->rows(3),
                    ]),
            ]),
        ];
    }

    /** Preisliste, nach der gerechnet wird – Grundlage fürs Costing (Buchung › Costing). */
    private static function priceList(): Select
    {
        return Select::make('price_list_id')
            ->label('Preisliste')
            ->options(fn (?Event $record): array => PriceList::choices($record?->price_list_id))
            ->helperText('Grundlage fürs Costing.')
            ->native(false);
    }

    /**
     * Leseansicht (ViewEvent): Wer Events nur lesen, aber die Buchhaltung
     * bearbeiten darf, ändert hier Saalplan, Bereiche und Bestuhlung – dieselben
     * Felder wie beim Bearbeiten (SeatingPlanFields).
     */
    private static function changeSeatingPlan(): Action
    {
        return Action::make('changeSeatingPlan')
            ->label('Saalplan ändern')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->visible(fn (Component $livewire): bool => $livewire instanceof ViewEvent && SeatingPlanFields::canChange())
            ->modalHeading('Saalplan ändern')
            ->fillForm(fn (Event $record): array => [
                'seating_plan_id' => $record->seating_plan_id,
                'seating_plan_path' => $record->seating_plan_path,
                'seating_plan_original_name' => $record->seating_plan_original_name,
                'areas' => $record->areas ?? [],
                'seating' => $record->seating ?? [],
            ])
            ->schema(fn (): array => [
                ...SeatingPlanFields::forEvent(),
                CheckboxList::make('areas')
                    ->label('Bereiche')
                    ->options(fn (?Event $record): array => OptionChoices::for(OptionField::Areas, $record?->areas))
                    ->columns(2),
                CheckboxList::make('seating')
                    ->label('Bestuhlung')
                    ->options(fn (?Event $record): array => OptionChoices::for(OptionField::Seating, $record?->seating)),
            ])
            ->action(function (array $data, Event $record, Component $livewire): void {
                abort_unless(SeatingPlanFields::canChange(), 403);
                $first = fn (mixed $value): mixed => is_array($value) ? (reset($value) ?: null) : $value;

                $record->update([
                    'seating_plan_id' => $data['seating_plan_id'] ?? null,
                    'seating_plan_path' => $first($data['seating_plan_path'] ?? null),
                    'seating_plan_original_name' => $first($data['seating_plan_original_name'] ?? null),
                    'areas' => array_values(array_map('strval', (array) ($data['areas'] ?? []))),
                    'seating' => array_values(array_map('strval', (array) ($data['seating'] ?? []))),
                ]);
                if ($livewire instanceof ViewEvent) {
                    $livewire->refreshFormData(['seating_plan_id', 'seating_plan_path', 'seating_plan_original_name', 'areas', 'seating']);
                }
                Notification::make()->success()->title('Saalplan gespeichert')->send();
            });
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
                    // Die Preisliste steht seit 13.10.2026 unter Buchung › Daten (Modul Preislisten & Costing)
                    TextInput::make('rent')
                        ->label('Miete')
                        ->numeric()
                        ->suffix('€'),
                    CheckboxList::make('accounting_status')
                        ->label('FIBU-Status')
                        ->options(fn (?EventFinance $record): array => OptionChoices::for(OptionField::AccountingStatus, $record?->accounting_status))
                        ->columns(2)
                        ->columnSpanFull(),
                    TagsInput::make('invoice_numbers')
                        ->label('Rechnungsnummern'),
                    Toggle::make('accounting_closed')
                        ->label('Abrechnung abgeschlossen'),
                ]),
            Section::make('Eingangsrechnungen')
                ->description('Welche Rechnungen von Dienstleistern erwartet werden und ob sie da sind. Mobiliar ist bei „bestuhlt“ automatisch erwartet, Cobra – Haus-Delay bei Haus-Delay (Durchführung › Betrieb) immer.')
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

    /**
     * Planung › Checkliste. Merch-Fee und „Merch-Fee eingesammelt“ stehen seit
     * dem 12.10.2026 unter Durchführung › Betrieb › Check, „Briefing vollständig“
     * gibt es nicht mehr (die Spalte bleibt mit ihren Altwerten stehen).
     */
    private static function checklist(): Section
    {
        return Section::make('Sind folgende Positionen geplant?')
            ->relationship('checklist')
            ->columns(3)
            ->schema(array_map(
                fn (string $field, string $label): ToggleButtons => ToggleButtons::make($field)
                    ->label($label)
                    ->options(self::CHECK_OPTIONS)
                    ->colors(['yes' => 'success', 'no' => 'danger', 'na' => 'gray'])
                    ->grouped(),
                array_keys(self::CHECKS),
                self::CHECKS,
            ));
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
     * Durchführung › Betrieb (Show Day) wie in der PHP-Version: Räume,
     * Zählerstände (seit 15.10.2026 je Zähler „je Event“, vorher nur Strom),
     * Bus-Strom, die Ja/Nein-Punkte des Tages, PAX.
     *
     * @return list<Section>
     */
    private static function execution(): array
    {
        return [
            Section::make('Räume')
                ->description('Jeder Raum einmal: Backstage, Büro oder – (nicht belegt).')
                ->columns(['default' => 1, 'lg' => 2, 'xl' => 3])
                ->schema(RoomUsageFields::all()),
            MeterFields::section(),
            // Vor dem Abschnitt „Check“: legt bei neuen Events die Zeile an, die Haus-Delay dort nur ändert
            Section::make('Bus-Strom')
                ->relationship('operation')
                ->schema([
                    ToggleButtons::make('bus_power')
                        ->hiddenLabel()
                        ->helperText('Anschlüsse, werden einzeln abgerechnet')
                        ->options(array_combine(range(0, EventOperation::MAX_BUS_POWER), array_map('strval', range(0, EventOperation::MAX_BUS_POWER))))
                        ->grouped(),
                ]),
            Section::make('Check')
                ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                ->schema([
                    self::yesNo('checklist', 'special_cleaning', 'Sonderreinigung', textValues: true),
                    self::yesNo('operation', 'house_delay', 'Haus-Delay')
                        ->helperText('Bei ja erwartet die Buchhaltung immer die Rechnung „Cobra – Haus-Delay“.'),
                    self::yesNo('checklist', 'power_ant', 'Miete Elektro-Ameise'),
                    self::yesNo('checklist', 'house_rig_early', 'Haus-Rig ab 7 Uhr'),
                    self::yesNo('stage', 'sold_out_award', 'Sold-Out-Award'),
                    TextInput::make('pax')
                        ->label('PAX abgerechnet')
                        ->numeric()
                        ->minValue(0),
                    // Bis 12.10.2026 unter Planung › Checkliste
                    self::relationText('checklist', 'merch_fee', 'Merch-Fee', 120),
                    self::yesNo('checklist', 'merch_fee_check', 'Merch-Fee eingesammelt', textValues: true, notApplicable: true),
                ]),
        ];
    }

    /** Textfeld aus einer anderen 1:1-Tabelle, gespeichert wie yesNo() per updateOrCreate. */
    private static function relationText(string $relation, string $column, string $label, int $maxLength): TextInput
    {
        return TextInput::make($column)
            ->label($label)
            ->maxLength($maxLength)
            ->dehydrated(false)
            ->afterStateHydrated(fn (TextInput $component, ?Event $record) => $component->state($record?->{$relation}?->{$column}))
            ->saveRelationshipsUsing(fn (Event $record, mixed $state) => $record->{$relation}()->updateOrCreate([], [
                $column => filled($state) ? trim((string) $state) : null,
            ]));
    }

    /**
     * Ja/nein für Werte aus anderen 1:1-Tabellen (Checkliste, Betrieb, Bühne).
     * Ohne Bindung gespeichert per updateOrCreate – ein zweiter Abschnitt auf
     * derselben Beziehung legte bei neuen Events die Zeile doppelt an. Leer
     * heißt: nicht erfasst. Textwerte wie in der Checkliste („yes“/„no“), aber
     * nur ja/nein (Wunsch vom 01.10.2026) – mit $notApplicable auch „entfällt“
     * (Merch-Fee eingesammelt: ohne Merch gibt es nichts einzusammeln).
     */
    private static function yesNo(string $relation, string $column, string $label, bool $textValues = false, bool $notApplicable = false): ToggleButtons
    {
        $field = ToggleButtons::make($column)
            ->label($label)
            ->grouped()
            ->dehydrated(false);
        $allowed = $notApplicable ? ['yes', 'no', 'na'] : ['yes', 'no'];
        $field = $textValues
            ? $field->options(array_intersect_key(self::CHECK_OPTIONS, array_flip($allowed)))
                ->colors(['yes' => 'success', 'no' => 'danger', 'na' => 'gray'])
                ->icons(['yes' => Heroicon::Check, 'no' => Heroicon::XMark])
            : $field->boolean('ja', 'nein');

        return $field
            // Für Ja/Nein als 1/0 wie die Schaltflächen: Filament wandelt vor diesem Aufruf um.
            ->afterStateHydrated(function (ToggleButtons $component, ?Event $record) use ($relation, $column, $textValues, $allowed): void {
                $value = $record?->{$relation}?->{$column};
                $component->state(match (true) {
                    $value === null => null,
                    $textValues => in_array($value, $allowed, true) ? $value : null,
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
                // „sonst. Podeste“ aus AppSheet, wenn dort Text statt einer Anzahl steht.
                // Anzahlen stehen in „Sonstige“, die Anmerkungen in „Anmerkungen Bühne“.
                TextEntry::make('legacy_other')
                    ->label('Sonstige Podeste laut AppSheet')
                    ->state(fn (?EventStage $record): ?string => $record?->other_info)
                    ->visible(fn (?EventStage $record): bool => filled($record?->other_info)),
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
