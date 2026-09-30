<?php

namespace App\Filament\Resources\Calendars;

use App\Filament\Resources\Calendars\Pages\ManageCalendars;
use App\Models\Calendar;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Kalender-Ebenen. Bei den Ebenen „Events“ und „Anfragen“ ist nur die Farbe
 * änderbar – der Code verlässt sich auf ihre Schlüssel und ihr Verhalten.
 */
class CalendarResource extends Resource
{
    protected static ?string $model = Calendar::class;

    protected static ?string $slug = 'kalender-ebenen';

    protected static ?string $modelLabel = 'Kalender-Ebene';

    protected static ?string $pluralModelLabel = 'Kalender-Ebenen';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    /** @var array<string, string> */
    public const FREITERMIN_OPTIONS = [
        'belegt' => 'Zählt als belegt',
        'zu_klaeren' => 'Zählt als „zu klären“',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('key')
                    ->label('Kennung')
                    ->helperText('Technischer Name, nur Kleinbuchstaben, Ziffern und _. Nach dem Anlegen fest.')
                    ->required()
                    ->maxLength(32)
                    ->regex('/^[a-z0-9_]+$/')
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?Calendar $record): bool => $record !== null),
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(120)
                    ->disabled(fn (?Calendar $record): bool => $record?->isLocked() ?? false),
                ColorPicker::make('color')
                    ->label('Farbe')
                    ->required()
                    ->regex('/^#[0-9a-fA-F]{6}$/'),
                Select::make('freitermin_status')
                    ->label('Bei der Freitermin-Abfrage')
                    ->options(self::FREITERMIN_OPTIONS)
                    ->placeholder('Zählt nicht')
                    ->disabled(fn (?Calendar $record): bool => $record?->isLocked() ?? false)
                    ->native(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                ColorColumn::make('color')
                    ->label('Farbe'),
                TextColumn::make('name')
                    ->label('Ebene')
                    ->searchable(),
                TextColumn::make('key')
                    ->label('Kennung')
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('freitermin_status')
                    ->label('Freitermin-Abfrage')
                    ->formatStateUsing(fn (?string $state): string => self::FREITERMIN_OPTIONS[$state] ?? 'Zählt nicht')
                    ->placeholder('Zählt nicht'),
                IconColumn::make('is_system')
                    ->label('System')
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
            'index' => ManageCalendars::route('/'),
        ];
    }
}
