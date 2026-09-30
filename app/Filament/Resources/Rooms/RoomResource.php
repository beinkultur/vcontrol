<?php

namespace App\Filament\Resources\Rooms;

use App\Filament\Resources\Rooms\Pages\ManageRooms;
use App\Models\Room;
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

class RoomResource extends Resource
{
    protected static ?string $model = Room::class;

    protected static ?string $slug = 'raeume';

    protected static ?string $modelLabel = 'Raum';

    protected static ?string $pluralModelLabel = 'Räume';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 30;

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
                Toggle::make('is_active')
                    ->label('Aktiv')
                    ->helperText('Inaktive Räume stehen bei neuen Events nicht zur Auswahl.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Raum')
                    ->searchable(),
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
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRooms::route('/'),
        ];
    }
}
