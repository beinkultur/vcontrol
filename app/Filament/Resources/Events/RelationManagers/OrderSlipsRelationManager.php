<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Access\Area;
use App\Filament\Forms\SignaturePad;
use App\Models\Article;
use App\Models\OrderSlip;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Durchführung › Bestellscheine wie in der PHP-Version: „Bestellt von“, Artikel
 * mit Menge (Preis und Summe aus der Artikelliste), optional Unterschrift;
 * „abgerechnet“ setzt die Buchhaltung.
 */
class OrderSlipsRelationManager extends RelationManager
{
    protected static string $relationship = 'orderSlips';

    protected static ?string $title = 'Bestellscheine';

    protected static ?string $modelLabel = 'Bestellschein';

    protected static ?string $pluralModelLabel = 'Bestellscheine';

    /**
     * Auch in der Ansicht des Events bearbeitbar: Event-Operationen sind wie in der
     * PHP-Version ein eigenes Recht (siehe Policy), etwa für den Hausmeister, der
     * Events nur lesen darf.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('ordered_from')
                    ->label('Bestellt von')
                    ->placeholder('z. B. Catering, Lieferant, Ansprechpartner')
                    ->required()
                    ->maxLength(200),
                DateTimePicker::make('ordered_at')
                    ->label('Bestellt am')
                    ->seconds(false)
                    ->default(fn () => now())
                    ->required(),
                Repeater::make('lines')
                    ->label('Artikel')
                    ->schema([
                        Select::make('article_id')
                            ->label('Artikel')
                            ->options(fn (): array => Article::groupedOptions())
                            ->searchable()
                            ->required()
                            ->live()
                            ->columnSpan(3),
                        TextInput::make('quantity')
                            ->label('Menge')
                            ->numeric()
                            ->minValue(0.01)
                            ->default(1)
                            ->required()
                            ->live(onBlur: true),
                        TextEntry::make('line_total')
                            ->label('Summe')
                            ->state(fn (Get $get): string => OrderSlip::money(self::lineTotal($get('article_id'), $get('quantity')))),
                    ])
                    ->columns(5)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Artikel hinzufügen')
                    ->reorderable(false)
                    ->columnSpanFull(),
                TextEntry::make('total')
                    ->label('Gesamtsumme')
                    ->state(fn (Get $get): string => OrderSlip::money(collect($get('lines') ?? [])->sum(fn (array $line): float => self::lineTotal($line['article_id'] ?? null, $line['quantity'] ?? null))))
                    ->weight('bold')
                    ->columnSpanFull(),
                Textarea::make('comment')
                    ->label('Anmerkungen / Hinweise')
                    ->rows(2)
                    ->columnSpanFull(),
                SignaturePad::make('signature')
                    ->label('Unterschrift (optional)')
                    ->helperText('Kann auch später ergänzt werden.')
                    ->columnSpanFull(),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextEntry::make('ordered_from')->label('Bestellt von'),
                TextEntry::make('ordered_at')->label('Bestellt am')->dateTime('d.m.Y H:i'),
                TextEntry::make('created_by_name')->label('Bei wem')->placeholder('–'),
                View::make('filament.events.order-slip-lines')
                    ->viewData(fn (OrderSlip $record): array => ['slip' => $record->loadMissing('items')])
                    ->columnSpanFull(),
                TextEntry::make('comment')->label('Anmerkungen / Hinweise')->placeholder('–')->columnSpan(2),
                IconEntry::make('is_settled')->label('Abgerechnet')->boolean(),
                View::make('filament.events.signature-image')
                    ->viewData(fn (OrderSlip $record): array => ['signature' => $record->signature, 'label' => 'Unterschrift'])
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ordered_from')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('items'))
            ->defaultSort('ordered_at', 'desc')
            ->columns([
                TextColumn::make('ordered_at')->label('Datum')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('ordered_from')->label('Bestellt von')->searchable(),
                TextColumn::make('created_by_name')->label('Bei wem')->placeholder('–'),
                TextColumn::make('items_count')
                    ->label('Positionen')
                    ->state(fn (OrderSlip $record): int => $record->items->count())
                    ->alignEnd(),
                TextColumn::make('total')
                    ->label('Summe')
                    ->state(fn (OrderSlip $record): string => $record->items->isEmpty() ? '–' : OrderSlip::money($record->total()))
                    ->alignEnd(),
                IconColumn::make('signature')
                    ->label('Unterschr.')
                    ->state(fn (OrderSlip $record): bool => filled($record->signature))
                    ->boolean()
                    ->alignCenter(),
                IconColumn::make('is_settled')->label('Abgerechnet')->boolean()->alignCenter(),
            ])
            ->paginated([25, 50, 'all'])
            ->emptyStateHeading('Noch keine Bestellscheine')
            ->headerActions([
                CreateAction::make()
                    ->label('Neuer Bestellschein')
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalWidth('4xl')
                    ->using(fn (array $data): OrderSlip => self::create($this->getOwnerRecord()->getKey(), $data)),
            ])
            ->recordActions([
                ViewAction::make()->modalWidth('4xl')->iconButton(),
                Action::make('sign')
                    ->iconButton()
                    ->label('Unterschrift ergänzen')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->authorize('update')
                    ->visible(fn (OrderSlip $record): bool => blank($record->signature))
                    ->schema([SignaturePad::make('signature')->label('Unterschrift')->required()])
                    ->modalSubmitActionLabel('Speichern')
                    ->action(fn (OrderSlip $record, array $data) => $record->update(['signature' => $data['signature']])),
                Action::make('settle')
                    ->iconButton()
                    ->label(fn (OrderSlip $record): string => $record->is_settled ? 'Abrechnung zurücknehmen' : 'Abgerechnet')
                    ->icon(fn (OrderSlip $record): Heroicon => $record->is_settled ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedCheckCircle)
                    ->color(fn (OrderSlip $record): string => $record->is_settled ? 'gray' : 'success')
                    ->visible(fn (): bool => self::canSettle())
                    ->action(fn (OrderSlip $record) => $record->update(['is_settled' => !$record->is_settled])),
            ]);
    }

    /** Abgerechnet setzt wie in der PHP-Version die Buchhaltung. */
    public static function canSettle(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->canEdit(Area::Buchhaltung);
    }

    /**
     * Bestellschein mit Positionen anlegen. Name, Einheit und Preis kommen aus der
     * Artikelliste und bleiben so stehen, auch wenn sich der Artikel später ändert.
     *
     * @param  array<string, mixed>  $data
     */
    public static function create(int $eventId, array $data): OrderSlip
    {
        return DB::transaction(function () use ($eventId, $data): OrderSlip {
            $slip = OrderSlip::create([
                'event_id' => $eventId,
                'ordered_from' => trim((string) $data['ordered_from']),
                'ordered_at' => $data['ordered_at'],
                'comment' => filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
                'signature' => $data['signature'] ?? null,
            ]);

            $lines = array_values($data['lines'] ?? []);
            $articles = Article::query()->with('category')->whereKey(array_column($lines, 'article_id'))->get()->keyBy('id');
            foreach ($lines as $index => $line) {
                $article = $articles->get((int) $line['article_id']);
                if ($article === null) {
                    continue;
                }
                $quantity = round((float) $line['quantity'], 2);
                $price = (float) $article->price;
                $slip->items()->create([
                    'article_id' => $article->id,
                    'category_id' => $article->category_id,
                    'category_name' => $article->category->name,
                    'article_name' => $article->name,
                    'unit' => $article->unit,
                    'unit_price' => $price,
                    'quantity' => $quantity,
                    'line_total' => round($price * $quantity, 2),
                    'sort_order' => $index,
                ]);
            }

            return $slip;
        });
    }

    private static function lineTotal(mixed $articleId, mixed $quantity): float
    {
        $price = $articleId ? (float) Article::query()->whereKey($articleId)->value('price') : 0.0;

        return round($price * (float) $quantity, 2);
    }
}
