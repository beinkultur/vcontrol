<?php

namespace App\Filament\Resources\Costings;

use App\Filament\Resources\Costings\Pages\EditCosting;
use App\Filament\Resources\Costings\Pages\ListCostings;
use App\Filament\Resources\Costings\Pages\ViewCosting;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\CostingActions;
use App\Models\Costing;
use App\Models\CostingItem;
use App\Models\PriceListItem;
use App\Support\Costings;
use App\Support\ExternSheet;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * Costings aller Events (Modul Preislisten & Costing): Liste für Eventmanager
 * und Buchhaltung, dazu Bearbeiten (Entwürfe) und Ansehen. Erstellt wird ein
 * Costing im Event unter Buchung › Costing (CostingsRelationManager), verschickt
 * mit CostingActions. Rechte: CostingPolicy.
 */
class CostingResource extends Resource
{
    protected static ?string $model = Costing::class;

    protected static ?string $slug = 'costings';

    protected static ?string $modelLabel = 'Costing';

    protected static ?string $pluralModelLabel = 'Costings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->schema([
                        TextEntry::make('event_title')
                            ->label('Event')
                            ->state(fn (Costing $record): string => $record->event->title . ' · ' . ExternSheet::date($record->event))
                            ->url(fn (Costing $record): ?string => self::eventUrl($record))
                            ->color('primary'),
                        TextEntry::make('price_list_label')
                            ->label('Preisliste')
                            ->state(fn (Costing $record): string => $record->priceList?->label() ?? 'ohne'),
                        TextEntry::make('version_label')
                            ->label('Fassung')
                            ->state(fn (Costing $record): string => (string) $record->version),
                        TextEntry::make('status_label')
                            ->label('Status')
                            ->state(fn (Costing $record): string => $record->isSent()
                                ? 'verschickt am ' . $record->sent_at->format('d.m.Y, H:i') . ' Uhr an ' . implode(', ', (array) $record->sent_to)
                                : 'Entwurf'),
                    ]),
                Section::make('Posten')
                    ->description('Faktor: Menge, Stunden, Tickets … – bei „%“ der Betrag in €, auf den der Zuschlag gilt. Faktor 0 steht nicht im PDF. '
                        . 'Preise aus der Preisliste bleiben fest; ohne Preis steht „nach Aufwand“ im PDF, außer du trägst eine Schätzung ein. Alle Preise netto.')
                    ->schema([self::items()]),
                Grid::make(['default' => 1, 'lg' => 3])
                    ->schema([
                        Textarea::make('note')
                            ->label('Anmerkung')
                            ->helperText('Steht im PDF unter den Posten, vor den Hinweisen der Preisliste.')
                            ->rows(4)
                            ->columnSpan(['lg' => 2]),
                        View::make('filament.costings.totals')
                            ->viewData(fn (Get $get, ?Costing $record): array => [
                                'totals' => Costings::totals((array) $get('items'), $record?->vat_rate ?? Costing::VAT_RATE),
                                'vatRate' => $record?->vat_rate ?? Costing::VAT_RATE,
                            ]),
                    ]),
            ]);
    }

    /**
     * Posten als Tabelle. Preise aus der Preisliste (fixed_price) bleiben fest –
     * auch beim Speichern, nicht nur im Formular. Ergänzte Posten sind frei.
     */
    private static function items(): Repeater
    {
        return Repeater::make('items')
            ->hiddenLabel()
            ->relationship()
            ->orderColumn('sort_order')
            ->table([
                TableColumn::make('Abschnitt')->width('10rem'),
                TableColumn::make('Posten')->markAsRequired(),
                TableColumn::make('Hinweis'),
                TableColumn::make('Einheit')->width('8rem'),
                TableColumn::make('Preis')->width('8rem'),
                TableColumn::make('Faktor')->width('7rem'),
                TableColumn::make('Betrag')->width('8rem')->alignment(Alignment::End),
            ])
            ->schema([
                TextInput::make('section')
                    ->label('Abschnitt')
                    ->datalist(fn (Component $livewire): array => self::sections($livewire))
                    ->maxLength(120),
                TextInput::make('name')
                    ->label('Posten')
                    ->required()
                    ->maxLength(255),
                TextInput::make('description')
                    ->label('Hinweis')
                    ->maxLength(500),
                TextInput::make('unit')
                    ->label('Einheit')
                    ->datalist(PriceListItem::UNITS)
                    ->maxLength(60)
                    ->live(onBlur: true),
                TextInput::make('price')
                    ->label('Preis')
                    ->numeric()
                    ->suffix(fn (Get $get): string => PriceListItem::isPercent($get('unit')) ? '%' : '€')
                    ->helperText(fn (Get $get): ?string => is_numeric($get('minimum')) ? 'mind. ' . Costings::money((float) $get('minimum')) : null)
                    ->disabled(fn (Get $get): bool => (bool) $get('fixed_price'))
                    ->live(onBlur: true),
                TextInput::make('factor')
                    ->label('Faktor')
                    ->numeric()
                    ->required()
                    ->default(1)
                    ->suffix(fn (Get $get): ?string => PriceListItem::isPercent($get('unit')) ? '€' : null)
                    ->live(onBlur: true),
                TextEntry::make('amount')
                    ->label('Betrag')
                    ->state(function (Get $get): string {
                        $amount = CostingItem::calculate($get('price'), $get('factor'), $get('unit'), $get('minimum'));

                        return $amount === null ? 'nach Aufwand' : Costings::money($amount);
                    })
                    ->alignEnd(),
            ])
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => [...$data, 'fixed_price' => false, 'price_list_item_id' => null])
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, CostingItem $record): array => $record->fixed_price ? [...$data, 'price' => $record->price] : $data)
            ->addActionLabel('Posten ergänzen')
            ->defaultItems(0);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event.starts_at')
                    ->label('Datum')
                    ->date('D, d.m.Y')
                    ->sortable(),
                TextColumn::make('event.title')
                    ->label('Event')
                    ->description(fn (Costing $record): ?string => $record->event?->promoter?->name)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('version')
                    ->label('Fassung')
                    ->alignEnd()
                    ->visibleFrom('md'),
                TextColumn::make('priceList.name')
                    ->label('Preisliste')
                    ->placeholder('–')
                    ->visibleFrom('lg'),
                TextColumn::make('net')
                    ->label('Netto')
                    ->state(fn (Costing $record): string => Costings::money($record->net()))
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (Costing $record): string => $record->statusLabel())
                    ->badge()
                    ->color(fn (Costing $record): string => $record->isSent() ? 'success' : 'gray')
                    ->visibleFrom('sm'),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['event.promoter', 'priceList', 'items']))
            ->filters([
                SelectFilter::make('state')
                    ->label('Status')
                    ->options(['draft' => 'Entwurf', 'sent' => 'verschickt'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'draft' => $query->whereNull('sent_at'),
                        'sent' => $query->whereNotNull('sent_at'),
                        default => $query,
                    }),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->recordUrl(fn (Costing $record): string => static::getUrl(static::canEdit($record) ? 'edit' : 'view', ['record' => $record]))
            ->recordActions([CostingActions::pdf()])
            ->emptyStateHeading('Noch keine Costings')
            ->emptyStateDescription('Ein Costing entsteht im Event unter Buchung › Costing.')
            ->paginated([25, 50, 100]);
    }

    /** Zum Event, Reiter Costing – bearbeiten, wer darf, sonst ansehen */
    public static function eventUrl(Costing $record): ?string
    {
        $event = $record->event;
        if (EventResource::canEdit($event)) {
            return EventResource::getUrl('edit', ['record' => $event]) . '?phase=buchung&bereich=costing';
        }

        return EventResource::canView($event) ? EventResource::getUrl('view', ['record' => $event]) . '?phase=buchung&bereich=costing' : null;
    }

    /** Abschnitte dieses Costings als Vorschläge @return list<string> */
    private static function sections(Component $livewire): array
    {
        $record = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

        return $record instanceof Costing ? $record->items->pluck('section')->filter()->unique()->values()->all() : [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCostings::route('/'),
            'view' => ViewCosting::route('/{record}'),
            'edit' => EditCosting::route('/{record}/edit'),
        ];
    }
}
