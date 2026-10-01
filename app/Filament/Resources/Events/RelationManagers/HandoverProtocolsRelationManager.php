<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Forms\SignaturePad;
use App\Models\HandoverProtocol;
use App\Models\InventoryItem;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Durchführung › Übergabeprotokolle wie in der PHP-Version: Inventar an einen
 * Empfänger übergeben (mit dessen Unterschrift), später zurückerhalten.
 */
class HandoverProtocolsRelationManager extends RelationManager
{
    protected static string $relationship = 'handoverProtocols';

    protected static ?string $title = 'Übergabeprotokolle';

    protected static ?string $modelLabel = 'Übergabeprotokoll';

    protected static ?string $pluralModelLabel = 'Übergabeprotokolle';

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
                TextInput::make('handed_to')
                    ->label('An wen (Empfänger)')
                    ->required()
                    ->maxLength(200),
                DateTimePicker::make('handed_at')
                    ->label('Übergeben am')
                    ->seconds(false)
                    ->default(fn () => now())
                    ->required(),
                Select::make('items')
                    ->label('Artikel')
                    ->multiple()
                    ->options(fn (): array => self::inventoryOptions())
                    ->searchable()
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('comment')
                    ->label('Anmerkungen')
                    ->rows(2)
                    ->columnSpanFull(),
                SignaturePad::make('signature')
                    ->label('Unterschrift Empfänger (optional)')
                    ->helperText('Kann auch später ergänzt werden.')
                    ->columnSpanFull(),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextEntry::make('handed_to')->label('An wen'),
                TextEntry::make('handed_at')->label('Übergeben am')->dateTime('d.m.Y H:i'),
                TextEntry::make('created_by_name')->label('Von')->placeholder('–'),
                TextEntry::make('status')
                    ->label('Status')
                    ->state(fn (HandoverProtocol $record): string => $record->statusLabel())
                    ->badge()
                    ->color(fn (HandoverProtocol $record): string => $record->isOpen() ? 'warning' : 'success'),
                TextEntry::make('returned_at')
                    ->label('Zurück erhalten am')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('–')
                    ->columnSpan(2),
                View::make('filament.events.handover-lines')
                    ->viewData(fn (HandoverProtocol $record): array => ['protocol' => $record->loadMissing('items')])
                    ->columnSpanFull(),
                TextEntry::make('comment')->label('Anmerkungen')->placeholder('–')->columnSpanFull(),
                View::make('filament.events.signature-image')
                    ->viewData(fn (HandoverProtocol $record): array => ['signature' => $record->signature, 'label' => 'Unterschrift Empfänger'])
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('handed_to')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('items'))
            ->defaultSort('handed_at', 'desc')
            ->columns([
                TextColumn::make('handed_at')->label('Datum')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('handed_to')->label('An wen')->searchable(),
                TextColumn::make('created_by_name')->label('Von')->placeholder('–'),
                TextColumn::make('items_list')
                    ->label('Artikel')
                    ->state(fn (HandoverProtocol $record): string => $record->items->pluck('item_name')->implode(', '))
                    ->limit(60)
                    ->tooltip(fn (HandoverProtocol $record): string => $record->items->pluck('item_name')->implode(', ')),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (HandoverProtocol $record): string => $record->statusLabel())
                    ->badge()
                    ->color(fn (HandoverProtocol $record): string => $record->isOpen() ? 'warning' : 'success'),
                IconColumn::make('signature')
                    ->label('Unterschr.')
                    ->state(fn (HandoverProtocol $record): bool => filled($record->signature))
                    ->boolean()
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([HandoverProtocol::OPEN => 'offen', HandoverProtocol::RETURNED => 'zurück']),
            ])
            ->paginated([25, 50, 'all'])
            ->emptyStateHeading('Noch keine Übergaben')
            ->headerActions([
                CreateAction::make()
                    ->label('Neue Übergabe')
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalWidth('3xl')
                    ->using(fn (array $data): HandoverProtocol => self::create($this->getOwnerRecord()->getKey(), $data)),
            ])
            ->recordActions([
                ViewAction::make()->modalWidth('3xl')->iconButton(),
                Action::make('return')
                    ->iconButton()
                    ->label('Zurückerhalten')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('success')
                    ->authorize('update')
                    ->visible(fn (HandoverProtocol $record): bool => $record->isOpen())
                    ->requiresConfirmation()
                    ->modalHeading('Als zurückerhalten markieren?')
                    ->action(fn (HandoverProtocol $record) => $record->update([
                        'status' => HandoverProtocol::RETURNED,
                        'returned_at' => now(),
                        'returned_by' => Auth::id(),
                    ])),
                Action::make('sign')
                    ->iconButton()
                    ->label('Unterschrift ergänzen')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->authorize('update')
                    ->visible(fn (HandoverProtocol $record): bool => blank($record->signature))
                    ->schema([SignaturePad::make('signature')->label('Unterschrift Empfänger')->required()])
                    ->modalSubmitActionLabel('Speichern')
                    ->action(fn (HandoverProtocol $record, array $data) => $record->update(['signature' => $data['signature']])),
            ]);
    }

    /**
     * Aktives Inventar nach Kategorie gruppiert.
     *
     * @return array<string, array<int, string>>
     */
    public static function inventoryOptions(): array
    {
        return InventoryItem::query()
            ->with('category')
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (InventoryItem $item): string => sprintf('%05d %s', $item->category?->sort_order ?? 99999, mb_strtolower($item->name)))
            ->groupBy(fn (InventoryItem $item): string => $item->category?->name ?? 'Sonstiges')
            ->map(fn ($items) => $items->mapWithKeys(fn (InventoryItem $item): array => [$item->id => $item->name])->all())
            ->all();
    }

    /**
     * Protokoll mit Artikeln anlegen; Name und Kategorie wie zum Zeitpunkt der Übergabe.
     *
     * @param  array<string, mixed>  $data
     */
    public static function create(int $eventId, array $data): HandoverProtocol
    {
        return DB::transaction(function () use ($eventId, $data): HandoverProtocol {
            $protocol = HandoverProtocol::create([
                'event_id' => $eventId,
                'handed_to' => trim((string) $data['handed_to']),
                'handed_at' => $data['handed_at'],
                'status' => HandoverProtocol::OPEN,
                'comment' => filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
                'signature' => $data['signature'] ?? null,
            ]);

            $items = InventoryItem::query()->with('category')->whereKey($data['items'] ?? [])->get()
                ->sortBy(fn (InventoryItem $item): string => sprintf('%05d %s', $item->category?->sort_order ?? 99999, mb_strtolower($item->name)))
                ->values();
            foreach ($items as $index => $item) {
                $protocol->items()->create([
                    'inventory_item_id' => $item->id,
                    'category_id' => $item->category_id,
                    'category_name' => $item->category?->name ?? '',
                    'item_name' => $item->name,
                    'sort_order' => $index,
                ]);
            }

            return $protocol;
        });
    }
}
