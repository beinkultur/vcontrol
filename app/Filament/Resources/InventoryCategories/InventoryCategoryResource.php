<?php

namespace App\Filament\Resources\InventoryCategories;

use App\Filament\Resources\InventoryCategories\Pages\ManageInventoryCategories;
use App\Models\InventoryCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class InventoryCategoryResource extends Resource
{
    protected static ?string $model = InventoryCategory::class;

    protected static ?string $slug = 'inventar-kategorien';

    protected static ?string $modelLabel = 'Inventar-Kategorie';

    protected static ?string $pluralModelLabel = 'Inventar-Kategorien';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 41;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(120),
                Toggle::make('is_active')
                    ->label('Aktiv')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Kategorie')
                    ->searchable(),
                TextColumn::make('items_count')
                    ->label('Artikel')
                    ->counts('items')
                    ->alignCenter(),
                IconColumn::make('is_active')
                    ->label('Aktiv')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
                // Nur leere Kategorien, siehe InventoryCategoryPolicy
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageInventoryCategories::route('/'),
        ];
    }
}
