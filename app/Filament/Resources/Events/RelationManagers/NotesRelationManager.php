<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Models\EventNote;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Notizen eines Events, neueste zuerst. In der Detailansicht nur lesend. */
class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $title = 'Notizen';

    protected static ?string $modelLabel = 'Notiz';

    protected static ?string $pluralModelLabel = 'Notizen';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('subject')
                    ->label('Betreff')
                    ->required()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (?string $state): string => trim((string) preg_replace('/\s+/u', ' ', (string) $state))),
                Textarea::make('body')
                    ->label('Text')
                    ->required()
                    ->rows(8)
                    ->dehydrateStateUsing(fn (?string $state): string => trim(str_replace(["\r\n", "\r"], "\n", (string) $state))),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
            ->columns([
                TextColumn::make('updated_at')
                    ->label('Datum')
                    ->date('d.m.Y'),
                TextColumn::make('subject')
                    ->label('Betreff')
                    ->description(fn (EventNote $record): string => $record->excerpt())
                    ->wrap()
                    ->searchable(['subject', 'body']),
                TextColumn::make('created_by_name')
                    ->label('Von')
                    ->description(fn (EventNote $record): ?string => filled($record->updated_by_name) && $record->updated_by_name !== $record->created_by_name
                        ? 'geändert von ' . $record->updated_by_name
                        : null),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->paginated([25, 50, 'all'])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
