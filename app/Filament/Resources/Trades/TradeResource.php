<?php

namespace App\Filament\Resources\Trades;

use App\Filament\Resources\Trades\Pages\ManageTrades;
use App\Models\Trade;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Gewerke werden archiviert, nicht gelöscht. */
class TradeResource extends Resource
{
    protected static ?string $model = Trade::class;

    protected static ?string $slug = 'gewerke';

    protected static ?string $modelLabel = 'Gewerk';

    protected static ?string $pluralModelLabel = 'Gewerke';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('short_name')
                    ->label('Kurzname')
                    ->maxLength(120),
                TagsInput::make('categories')
                    ->label('Leistungsbereiche')
                    ->suggestions(Trade::CATEGORY_SUGGESTIONS)
                    ->placeholder('Bereich hinzufügen'),
                TextInput::make('email')
                    ->label('E-Mail')
                    ->email()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label('Telefon')
                    ->tel()
                    ->maxLength(50),
                TextInput::make('address1')
                    ->label('Straße')
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('address2')
                    ->label('Adresszusatz')
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('zip')
                    ->label('PLZ')
                    ->maxLength(20),
                TextInput::make('city')
                    ->label('Ort')
                    ->maxLength(120),
                Toggle::make('is_archived')
                    ->label('Archiviert')
                    ->helperText('Archivierte Gewerke erscheinen nicht mehr in Auswahllisten.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('short_name')
                    ->label('Kurzname')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('categories')
                    ->label('Leistungsbereiche')
                    ->badge(),
                TextColumn::make('city')
                    ->label('Ort')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Telefon')
                    ->toggleable(),
                TextColumn::make('email')
                    ->label('E-Mail')
                    ->copyable()
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->recordActions([
                EditAction::make()->modalWidth(Width::ThreeExtraLarge),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTrades::route('/'),
        ];
    }
}
