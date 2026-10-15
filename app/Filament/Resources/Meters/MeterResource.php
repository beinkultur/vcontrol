<?php

namespace App\Filament\Resources\Meters;

use App\Filament\Pages\Meters;
use App\Filament\Resources\Meters\Pages\ManageMeters;
use App\Models\Meter;
use BackedEnum;
use Filament\Actions\Action;
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

/**
 * Zähler der Venue (seit 15.10.2026): Strom, Wasser, Fernwärme … mit Einheit,
 * Zählernummer und Standort. „Je Event ablesen“ bringt den Zähler ins Event
 * (Durchführung › Betrieb, Stand Anfang/Ende); abgelesen wird sonst frei auf
 * der Seite Zählerstände, die auch auswertet. Rechte: MeterPolicy.
 */
class MeterResource extends Resource
{
    protected static ?string $model = Meter::class;

    protected static ?string $slug = 'zaehler/verwaltung';

    protected static ?string $modelLabel = 'Zähler';

    protected static ?string $pluralModelLabel = 'Zähler';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 32;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->helperText('z. B. Strom, Wasser, Fernwärme')
                    ->required()
                    ->maxLength(120)
                    ->unique(ignoreRecord: true),
                TextInput::make('unit')
                    ->label('Einheit')
                    ->helperText('Nachträglich nur ändern, wenn die Stände schon in dieser Einheit erfasst sind.')
                    ->required()
                    ->maxLength(20)
                    ->datalist(['kWh', 'MWh', 'm³', 'l']),
                TextInput::make('number')
                    ->label('Zählernummer')
                    ->maxLength(60),
                TextInput::make('location')
                    ->label('Standort')
                    ->maxLength(120),
                Toggle::make('per_event')
                    ->label('Je Event ablesen')
                    ->helperText('Stand am Anfang und am Ende jedes Events, im Event unter Durchführung › Betrieb.'),
                Toggle::make('is_active')
                    ->label('Aktiv')
                    ->helperText('Inaktive Zähler stehen nicht mehr zur Erfassung; ihre Stände und die Auswertung bleiben.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount('readings'))
            ->columns([
                TextColumn::make('name')
                    ->label('Zähler')
                    ->description(fn (Meter $record): ?string => collect([$record->number, $record->location])->filter()->implode(' · ') ?: null)
                    ->searchable(),
                TextColumn::make('unit')
                    ->label('Einheit'),
                IconColumn::make('per_event')
                    ->label('Je Event')
                    ->boolean()
                    ->alignCenter(),
                TextColumn::make('readings_count')
                    ->label('Stände')
                    ->alignEnd(),
                IconColumn::make('is_active')
                    ->label('Aktiv')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->paginated(false)
            ->recordActions([
                Action::make('analysis')
                    ->label('Auswertung')
                    ->icon(Heroicon::OutlinedChartBar)
                    ->color('gray')
                    ->visible(fn (): bool => Meters::canAccess())
                    ->url(fn (Meter $record): string => Meters::getUrl(['zaehler' => $record->id])),
                EditAction::make(),
                // Nur ohne Stände, siehe MeterPolicy
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMeters::route('/'),
        ];
    }
}
