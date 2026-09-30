<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Access\Area;
use App\Access\Level;
use App\Enums\OptionField;
use App\Filament\Support\AssignmentFields;
use App\Filament\Support\IncomingInvoiceFields;
use App\Filament\Support\OptionChoices;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Event;
use App\Models\EventFinance;
use App\Models\EventPr;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Event-Workspace, erste Ausbaustufe: Kerndaten und 1:1-Zusatzdaten in den drei
 * Phasen der PHP-Version. Listen (Leistungen, Rollen, Räume) folgen.
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

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('Phasen')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Buchung')->schema(self::booking()),
                        Tab::make('Planung')->schema(self::planning()),
                        Tab::make('Durchführung')->schema(self::execution()),
                    ]),
            ]);
    }

    /** @return list<Section> */
    private static function booking(): array
    {
        return [
            Section::make('Stammdaten')
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
                        ->preload()
                        ->helperText('Die VA-ID setzt sich aus seiner Kundennummer und der laufenden Nummer zusammen.'),
                    Select::make('status')
                        ->label('VA-Status')
                        ->options(fn (?Event $record): array => OptionChoices::for(OptionField::VaStatus, $record?->status))
                        ->native(false),
                    DateTimePicker::make('starts_at')
                        ->label('Beginn')
                        ->required()
                        ->seconds(false),
                    DateTimePicker::make('ends_at')
                        ->label('Ende')
                        ->seconds(false)
                        ->afterOrEqual('starts_at'),
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
                    TextInput::make('va_nr')
                        ->label('Laufende Nummer')
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder('wird beim Anlegen vergeben'),
                    TextInput::make('va_id')
                        ->label('VA-ID')
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder('wird beim Anlegen vergeben'),
                    Textarea::make('description')
                        ->label('Beschreibung')
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('booking_notes')
                        ->label('Interne Buchungsnotizen')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
            // Finanzen nur mit Recht auf die Buchhaltung, bearbeiten nur mit Schreibrecht dort.
            // In Abschnitten mit relationship() ist $record das zugehörige Modell, nicht das Event.
            Section::make('Finanzen')
                ->relationship('finance')
                ->columns(2)
                ->visible(fn (): bool => self::can(Area::Buchhaltung))
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
                ->visible(fn (?Event $record): bool => $record !== null && self::can(Area::Buchhaltung))
                ->disabled(fn (): bool => !self::can(Area::Buchhaltung, Level::Edit))
                ->schema(IncomingInvoiceFields::all()),
            Section::make('PR')
                ->relationship('pr')
                ->columns(2)
                ->schema([
                    DatePicker::make('pr_date')
                        ->label('PR-Datum'),
                    Select::make('pr_status')
                        ->label('PR-Status')
                        ->options(fn (?EventPr $record): array => OptionChoices::for(OptionField::PrStatus, $record?->pr_status))
                        ->native(false),
                ]),
        ];
    }

    /** @return list<Section> */
    private static function planning(): array
    {
        return [
            Section::make('Halle')
                ->columns(3)
                ->schema([
                    CheckboxList::make('areas')
                        ->label('Bereiche')
                        ->options(fn (?Event $record): array => OptionChoices::for(OptionField::Areas, $record?->areas)),
                    CheckboxList::make('seating')
                        ->label('Bestuhlung')
                        ->options(fn (?Event $record): array => OptionChoices::for(OptionField::Seating, $record?->seating)),
                    Select::make('ticketing')
                        ->label('Ticketing')
                        ->options(fn (?Event $record): array => OptionChoices::for(OptionField::Ticketing, $record?->ticketing))
                        ->native(false),
                    TextInput::make('pax_expected')
                        ->label('PAX erwartet')
                        ->numeric()
                        ->minValue(0),
                    TextInput::make('onsite_contact')
                        ->label('Ansprechpartner vor Ort')
                        ->maxLength(120),
                    TextInput::make('wlan')
                        ->label('WLAN')
                        ->maxLength(120),
                    TextInput::make('wlan_password')
                        ->label('WLAN-Passwort')
                        ->maxLength(120),
                ]),
            Section::make('Zeiten')
                ->relationship('schedule')
                ->columns(4)
                ->schema(array_map(
                    fn (string $field, string $label): TimePicker => TimePicker::make($field)->label($label)->seconds(false),
                    array_keys(self::TIMES),
                    self::TIMES,
                )),
            Section::make('Räume')
                ->columns(2)
                ->schema([
                    self::roomField('backstageRooms', 'Backstage'),
                    self::roomField('officeRooms', 'Büros'),
                ]),
            Section::make('Rollen am Event')
                ->columns(2)
                ->schema(AssignmentFields::all()),
        ];
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

    /** @return list<Section> */
    private static function execution(): array
    {
        return [
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
                    Toggle::make('doing_closed')
                        ->label('Durchführung abgeschlossen'),
                    Toggle::make('closed')
                        ->label('Event abgeschlossen'),
                ]),
        ];
    }

    private static function can(Area $area, Level $minimum = Level::Read): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can($area, $minimum);
    }
}
