<?php

namespace App\Filament\Resources\OrderSlips;

use App\Access\Area;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\RelationManagers\OrderSlipsRelationManager;
use App\Filament\Resources\OrderSlips\Pages\ListOrderSlips;
use App\Models\OrderSlip;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Alle Bestellscheine über die Events hinweg wie /protokolle/bestellscheine der
 * PHP-Version (Recht „Protokolle“); „abgerechnet“ setzt die Buchhaltung.
 */
class OrderSlipResource extends Resource
{
    protected static ?string $model = OrderSlip::class;

    protected static ?string $slug = 'bestellscheine';

    protected static ?string $modelLabel = 'Bestellschein';

    protected static ?string $pluralModelLabel = 'Bestellscheine';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Protokolle';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can(Area::Protokolle);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['event', 'items']))
            ->defaultSort('ordered_at', 'desc')
            ->columns([
                TextColumn::make('ordered_at')->label('Datum')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('event.title')
                    ->label('Event')
                    ->description(fn (OrderSlip $record): ?string => $record->event?->starts_at?->format('d.m.Y'))
                    ->searchable(),
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
                IconColumn::make('is_settled')->label('Abgerechnet')->boolean()->alignCenter(),
            ])
            ->filters([
                TernaryFilter::make('is_settled')->label('Abgerechnet'),
            ])
            ->recordUrl(fn (OrderSlip $record): ?string => $record->event === null ? null
                : EventResource::getUrl(EventResource::canEdit($record->event) ? 'edit' : 'view', ['record' => $record->event]) . '?phase=durchfuehrung&bereich=bestellscheine')
            ->recordActions([
                Action::make('settle')
                    ->label(fn (OrderSlip $record): string => $record->is_settled ? 'Abrechnung zurücknehmen' : 'Abgerechnet')
                    ->icon(fn (OrderSlip $record): Heroicon => $record->is_settled ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedCheckCircle)
                    ->color(fn (OrderSlip $record): string => $record->is_settled ? 'gray' : 'success')
                    ->visible(fn (): bool => OrderSlipsRelationManager::canSettle())
                    ->action(fn (OrderSlip $record) => $record->update(['is_settled' => !$record->is_settled])),
            ])
            ->paginated([50, 100, 'all'])
            ->emptyStateHeading('Noch keine Bestellscheine');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrderSlips::route('/'),
        ];
    }
}
