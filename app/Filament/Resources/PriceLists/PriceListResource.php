<?php

namespace App\Filament\Resources\PriceLists;

use App\Enums\OptionField;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\PriceLists\Pages\CreatePriceList;
use App\Filament\Resources\PriceLists\Pages\EditPriceList;
use App\Filament\Resources\PriceLists\Pages\ListPriceLists;
use App\Filament\Resources\PriceLists\Pages\ViewPriceList;
use App\Filament\Support\OptionChoices;
use App\Models\PriceList;
use App\Models\PriceListItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Component;
use UnitEnum;

/**
 * Preislisten der Venue (Modul Preislisten & Costing) mit ihren Kostenpunkten
 * nach Abschnitten, wie die gedruckte Preisliste. Events wählen eine unter
 * Buchung › Daten, das Costing übernimmt daraus Kostenpunkte, Preise und
 * Vorgabe-Faktoren. Rechte: PriceListPolicy.
 */
class PriceListResource extends Resource
{
    protected static ?string $model = PriceList::class;

    protected static ?string $slug = 'preislisten';

    protected static ?string $modelLabel = 'Preisliste';

    protected static ?string $pluralModelLabel = 'Preislisten';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 36;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Preisliste')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->placeholder('z. B. Konzert-Preisliste 2025-09')
                            ->required()
                            ->maxLength(120)
                            ->unique(ignoreRecord: true),
                        DatePicker::make('valid_from')
                            ->label('Gültig ab'),
                        Textarea::make('intro')
                            ->label('Kopf')
                            ->helperText('Steht im Costing über den Posten, etwa die Maximalkapazität.')
                            ->rows(3),
                        Textarea::make('notes')
                            ->label('Anmerkungen')
                            ->helperText('Stehen im Costing unter den Posten: Hinweise und Fußnoten der Preisliste.')
                            ->rows(3),
                        Toggle::make('is_active')
                            ->label('Aktiv')
                            ->helperText('Inaktive Preislisten stehen bei Events nicht mehr zur Auswahl; Events, die sie nutzen, behalten sie.')
                            ->default(true),
                    ]),
                Section::make('Kostenpunkte')
                    ->description('In der Reihenfolge der Preisliste, nach Abschnitten. Einheiten kommen aus Verwaltung › Event-Feldoptionen › Einheit (Preislisten). '
                        . 'Ohne Preis: nach Aufwand. Einheit „%“: Zuschlag in Prozent – im Costing ist der Faktor dann der Betrag in €, auf den er gilt. '
                        . 'Faktor, PAX und Im Costing sind Vorgaben für neue Costings: Faktor leer = 0; „PAX“ nimmt stattdessen die erwarteten PAX des Events, '
                        . 'etwa „pro verkauftem Ticket“; „Im Costing“ aus startet den Kostenpunkt ausgeschaltet, etwa eine Alternative.')
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->table([
                                TableColumn::make('Abschnitt')->width('10rem'),
                                TableColumn::make('Kostenpunkt')->markAsRequired(),
                                TableColumn::make('Hinweis'),
                                TableColumn::make('Einheit')->width('9rem'),
                                TableColumn::make('Preis')->width('8rem'),
                                TableColumn::make('Mindestbetrag')->width('8rem'),
                                TableColumn::make('Faktor')->width('6rem'),
                                TableColumn::make('PAX')->width('4rem')->alignment(Alignment::Center),
                                TableColumn::make('Im Costing')->width('6rem')->alignment(Alignment::Center),
                            ])
                            ->schema([
                                TextInput::make('section')
                                    ->label('Abschnitt')
                                    ->datalist(fn (): array => self::sections())
                                    ->maxLength(120),
                                TextInput::make('name')
                                    ->label('Kostenpunkt')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('description')
                                    ->label('Hinweis')
                                    ->maxLength(500),
                                Select::make('unit')
                                    ->label('Einheit')
                                    ->options(fn (Get $get): array => OptionChoices::forRows(OptionField::CostUnit, $get('unit')))
                                    ->live(),
                                MoneyInput::make('price')
                                    ->label('Preis')
                                    ->suffix(fn (Get $get): string => PriceListItem::isPercent($get('unit')) ? '%' : '€'),
                                MoneyInput::make('minimum')
                                    ->label('Mindestbetrag')
                                    ->minValue(0)
                                    ->suffix('€'),
                                TextInput::make('default_factor')
                                    ->label('Faktor')
                                    ->numeric(),
                                Checkbox::make('factor_from_pax')
                                    ->label('Faktor = PAX erwartet'),
                                Toggle::make('default_active')
                                    ->label('Im neuen Costing eingeschaltet')
                                    ->default(true),
                            ])
                            ->addActionLabel('Kostenpunkt hinzufügen')
                            ->defaultItems(0),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Preisliste')
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('valid_from')
                    ->label('Gültig ab')
                    ->date('d.m.Y')
                    ->placeholder('–')
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('items_count')
                    ->label('Kostenpunkte')
                    ->counts('items')
                    ->alignEnd()
                    ->visibleFrom('md'),
                TextColumn::make('events_count')
                    ->label('Events')
                    ->counts('events')
                    ->alignEnd()
                    ->visibleFrom('lg'),
                IconColumn::make('is_active')
                    ->label('Aktiv')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->defaultSort(fn ($query) => $query->orderByDesc('is_active')->orderByDesc('valid_from')->orderBy('name'))
            ->paginated(false)
            ->emptyStateHeading('Noch keine Preislisten')
            ->recordUrl(fn (PriceList $record): string => static::getUrl(static::canEdit($record) ? 'edit' : 'view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (PriceList $record): bool => !static::canEdit($record)),
                EditAction::make(),
                self::copyAction(),
                DeleteAction::make(),
            ]);
    }

    /** Kopie als Grundlage der nächsten Preisliste: neuer Name, inaktiv, bis sie fertig ist. */
    public static function copyAction(): Action
    {
        return Action::make('copy')
            ->label('Kopieren')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->authorize(fn (): bool => static::canCreate())
            ->modalHeading('Preisliste kopieren')
            ->modalDescription('Mit allen Kostenpunkten, als inaktive Preisliste – aktiv schalten, wenn sie fertig ist.')
            ->modalSubmitActionLabel('Kopieren')
            ->fillForm(fn (PriceList $record): array => ['name' => mb_substr($record->name . ' (Kopie)', 0, 120)])
            ->schema([
                TextInput::make('name')
                    ->label('Name der Kopie')
                    ->required()
                    ->maxLength(120)
                    ->unique(PriceList::class, 'name'),
            ])
            ->action(function (array $data, PriceList $record, Component $livewire): void {
                $copy = $record->duplicate(trim((string) $data['name']));
                Notification::make()->success()->title('Preisliste kopiert')->send();
                $livewire->redirect(static::getUrl('edit', ['record' => $copy]));
            });
    }

    /** Abschnitte aller Preislisten als Vorschläge, einmal je Anfrage gelesen @return list<string> */
    private static function sections(): array
    {
        return once(fn (): array => PriceListItem::query()->whereNotNull('section')->distinct()->orderBy('section')->pluck('section')->all());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceLists::route('/'),
            'create' => CreatePriceList::route('/create'),
            'view' => ViewPriceList::route('/{record}'),
            'edit' => EditPriceList::route('/{record}/edit'),
        ];
    }
}
