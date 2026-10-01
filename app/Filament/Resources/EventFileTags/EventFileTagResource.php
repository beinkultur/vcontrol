<?php

namespace App\Filament\Resources\EventFileTags;

use App\Filament\Resources\EventFileTags\Pages\ManageEventFileTags;
use App\Models\EventFileTag;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Tags der Event-Dateien wie in der PHP-Version (/dateien/tags): benutzte werden archiviert statt gelöscht. */
class EventFileTagResource extends Resource
{
    protected static ?string $model = EventFileTag::class;

    protected static ?string $slug = 'datei-tags';

    protected static ?string $modelLabel = 'Datei-Tag';

    protected static ?string $pluralModelLabel = 'Datei-Tags';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 45;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(120)
                    ->unique(ignoreRecord: true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Tag')
                    ->searchable(),
                TextColumn::make('files_count')
                    ->label('Dateien')
                    ->counts('files')
                    ->alignCenter(),
                IconColumn::make('is_archived')
                    ->label('Archiviert')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->filters([
                TernaryFilter::make('is_archived')
                    ->label('Archiviert')
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('is_archived', true),
                        false: fn (Builder $query): Builder => $query->where('is_archived', false),
                    ),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
                Action::make('archive')
                    ->label(fn (EventFileTag $record): string => $record->is_archived ? 'Wiederherstellen' : 'Archivieren')
                    ->icon(fn (EventFileTag $record): Heroicon => $record->is_archived ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedArchiveBox)
                    ->color('gray')
                    ->authorize('update')
                    ->action(fn (EventFileTag $record) => $record->update(['is_archived' => !$record->is_archived])),
                // Nur unbenutzte Tags, siehe EventFileTagPolicy
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEventFileTags::route('/'),
        ];
    }
}
