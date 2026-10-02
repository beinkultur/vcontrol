<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Support\DaysheetActions;
use App\Models\Daysheet;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Durchführung › Daysheets: wer wann an wen ein Daysheet verschickt hat und ob
 * der Link noch gilt. Ein Link lässt sich sperren, etwa nach einem Tippfehler in
 * der Adresse; neu verschicken geht jederzeit.
 */
class DaysheetsRelationManager extends RelationManager
{
    protected static string $relationship = 'daysheets';

    protected static ?string $title = 'Daysheets';

    protected static ?string $modelLabel = 'Daysheet';

    protected static ?string $pluralModelLabel = 'Daysheets';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Versendet')
                    ->dateTime('d.m.Y H:i'),
                TextColumn::make('created_by_name')
                    ->label('Von')
                    ->placeholder('–'),
                TextColumn::make('recipients')
                    ->label('Empfänger')
                    ->state(fn (Daysheet $record): string => implode(', ', $record->allRecipients()))
                    ->wrap(),
                TextColumn::make('expires_at')
                    ->label('Gültig bis')
                    ->dateTime('d.m.Y H:i'),
                TextColumn::make('status')
                    ->label('Link')
                    ->state(fn (Daysheet $record): string => $record->statusLabel())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'gültig' => 'success',
                        'gesperrt' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->headerActions([
                DaysheetActions::send()->label('Daysheet versenden'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Link sperren')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Der Link funktioniert danach nicht mehr – auch nicht für die Empfänger.')
                    ->authorize('update')
                    ->visible(fn (Daysheet $record): bool => $record->isValid())
                    ->action(fn (Daysheet $record) => $record->update(['revoked_at' => now()])),
            ])
            ->paginated(false)
            ->emptyStateHeading('Noch kein Daysheet verschickt')
            ->emptyStateDescription('Kurz vor der Veranstaltung: „Daysheet versenden“ – die Gewerke stehen schon im BCC.');
    }
}
