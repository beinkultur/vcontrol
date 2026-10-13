<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Resources\Costings\CostingResource;
use App\Filament\Support\CostingActions;
use App\Models\Costing;
use App\Support\Costings;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Buchung › Costing: die Fassungen dieses Events, neueste zuerst. „Costing
 * erstellen“ übernimmt die Preisliste des Events; bearbeitet wird auf der Seite
 * des Costings (CostingResource), verschickt als PDF (CostingActions).
 */
class CostingsRelationManager extends RelationManager
{
    protected static string $relationship = 'costings';

    protected static ?string $title = 'Costing';

    protected static ?string $modelLabel = 'Costing';

    protected static ?string $pluralModelLabel = 'Costings';

    /** Die Rollen fürs Event (Verwaltung › Halle) – Filament prüfte sonst den Menüpunkt (viewAny) */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return CostingResource::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['priceList', 'items']))
            ->defaultSort(fn ($query) => $query->orderByDesc('version'))
            ->columns([
                TextColumn::make('version')
                    ->label('Fassung')
                    ->formatStateUsing(fn (int $state): string => 'Fassung ' . $state)
                    ->weight('medium'),
                TextColumn::make('priceList.name')
                    ->label('Preisliste')
                    ->placeholder('ohne')
                    ->visibleFrom('md'),
                TextColumn::make('net')
                    ->label('Netto')
                    ->state(fn (Costing $record): string => Costings::money($record->net()))
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (Costing $record): string => $record->statusLabel())
                    ->badge()
                    ->color(fn (Costing $record): string => $record->isSent() ? 'success' : 'gray'),
                TextColumn::make('sent_to')
                    ->label('An')
                    ->state(fn (Costing $record): string => implode(', ', (array) $record->sent_to))
                    ->placeholder('–')
                    ->wrap()
                    ->visibleFrom('lg'),
            ])
            ->headerActions([
                CostingActions::create(),
            ])
            ->recordUrl(fn (Costing $record): string => CostingResource::getUrl(CostingResource::canEdit($record) ? 'edit' : 'view', ['record' => $record]))
            ->recordActions([
                CostingActions::pdf(),
                Action::make('open')
                    ->label(fn (Costing $record): string => CostingResource::canEdit($record) ? 'Bearbeiten' : 'Ansehen')
                    ->icon(fn (Costing $record): Heroicon => CostingResource::canEdit($record) ? Heroicon::OutlinedPencilSquare : Heroicon::OutlinedEye)
                    ->url(fn (Costing $record): string => CostingResource::getUrl(CostingResource::canEdit($record) ? 'edit' : 'view', ['record' => $record])),
                CostingActions::duplicate()
                    ->visible(fn (Costing $record): bool => $record->isSent()),
                DeleteAction::make(),
            ])
            ->paginated(false)
            ->emptyStateHeading('Noch kein Costing')
            ->emptyStateDescription('„Costing erstellen“ übernimmt die Kostenpunkte der Preisliste dieses Events.');
    }
}
