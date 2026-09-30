<?php

namespace App\Filament\Resources\InventoryItems;

use App\Filament\Resources\InventoryItems\Pages\ManageInventoryItems;
use App\Models\InventoryItem;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use UnitEnum;

/** Inventarartikel, nach Kategorie gruppiert. Deaktivieren statt löschen. */
class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static ?string $slug = 'inventar';

    protected static ?string $modelLabel = 'Artikel';

    protected static ?string $pluralModelLabel = 'Inventar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Select::make('category_id')
                    ->label('Kategorie')
                    ->relationship('category', 'name', fn ($query) => $query->orderBy('sort_order'))
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('name')
                    ->label('Artikel')
                    ->required()
                    ->maxLength(200),
                Toggle::make('is_active')
                    ->label('Aktiv')
                    ->helperText('Inaktive Artikel bleiben in alten Protokollen erhalten, sind aber nicht mehr wählbar.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Artikel')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Kategorie')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Aktiv')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->groups([
                Group::make('category.name')
                    ->label('Kategorie')
                    ->collapsible(),
            ])
            ->defaultGroup('category.name')
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('category')
                    ->label('Kategorie')
                    ->relationship('category', 'name'),
                TernaryFilter::make('is_active')
                    ->label('Aktiv')
                    ->default(true),
            ])
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageInventoryItems::route('/'),
        ];
    }
}
