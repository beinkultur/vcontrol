<?php

namespace App\Filament\Resources\Events\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/** Gästeliste eines Events mit Freikarten. In der Detailansicht nur lesend. */
class GuestsRelationManager extends RelationManager
{
    protected static string $relationship = 'guests';

    protected static ?string $title = 'Gästeliste';

    protected static ?string $modelLabel = 'Gast';

    protected static ?string $pluralModelLabel = 'Gäste';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextInput::make('first_name')
                    ->label('Vorname')
                    ->required()
                    ->maxLength(120),
                TextInput::make('last_name')
                    ->label('Name')
                    ->required()
                    ->maxLength(120),
                TextInput::make('free_tickets')
                    ->label('Freikarten')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999)
                    ->default(0)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('last_name')
            ->columns([
                TextColumn::make('last_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('first_name')
                    ->label('Vorname')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('free_tickets')
                    ->label('Freikarten')
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label('Gesamt')),
            ])
            ->defaultSort('last_name')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->headerActions([
                Action::make('print')
                    ->label('Drucken')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->url(fn (): string => route('events.guest-list-print', $this->getOwnerRecord()))
                    ->openUrlInNewTab(),
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => $data + ['created_by' => Auth::id()]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
