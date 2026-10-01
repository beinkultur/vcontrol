<?php

namespace App\Filament\Resources\HandoverProtocols;

use App\Access\Area;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\HandoverProtocols\Pages\ListHandoverProtocols;
use App\Models\HandoverProtocol;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Alle Übergabeprotokolle über die Events hinweg wie /protokolle/uebergabe der
 * PHP-Version (Recht „Protokolle“). Angelegt wird im Event unter Durchführung.
 */
class HandoverProtocolResource extends Resource
{
    protected static ?string $model = HandoverProtocol::class;

    protected static ?string $slug = 'uebergabeprotokolle';

    protected static ?string $modelLabel = 'Übergabeprotokoll';

    protected static ?string $pluralModelLabel = 'Übergabeprotokolle';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Protokolle';

    protected static ?int $navigationSort = 10;

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
            ->defaultSort('handed_at', 'desc')
            ->columns([
                TextColumn::make('handed_at')->label('Datum')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('event.title')
                    ->label('Event')
                    ->description(fn (HandoverProtocol $record): ?string => $record->event?->starts_at?->format('d.m.Y'))
                    ->searchable(),
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
                TextColumn::make('returned_at')->label('Zurück am')->dateTime('d.m.Y H:i')->placeholder('–'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([HandoverProtocol::OPEN => 'offen', HandoverProtocol::RETURNED => 'zurück']),
            ])
            ->recordUrl(fn (HandoverProtocol $record): ?string => $record->event === null ? null
                : EventResource::getUrl(EventResource::canEdit($record->event) ? 'edit' : 'view', ['record' => $record->event]) . '?phase=durchfuehrung&bereich=uebergabe')
            ->paginated([50, 100, 'all'])
            ->emptyStateHeading('Noch keine Übergabeprotokolle');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHandoverProtocols::route('/'),
        ];
    }
}
