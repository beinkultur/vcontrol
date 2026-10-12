<?php

namespace App\Filament\Resources\SeatingPlans;

use App\Enums\OptionField;
use App\Filament\Resources\SeatingPlans\Pages\ManageSeatingPlans;
use App\Filament\Support\OptionChoices;
use App\Filament\Support\SeatingPlanFields;
use App\Models\SeatingPlan;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
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
 * Standard-Saalpläne der Halle: PDF, Name, die Bereiche, die der Plan nutzt,
 * und seine Bestuhlung. Events wählen einen davon unter Buchung › Daten und
 * übernehmen dabei Bereiche und Bestuhlung (App\Filament\Support\SeatingPlanFields).
 * Rechte: SeatingPlanPolicy.
 */
class SeatingPlanResource extends Resource
{
    protected static ?string $model = SeatingPlan::class;

    protected static ?string $slug = 'saalplaene';

    protected static ?string $modelLabel = 'Saalplan';

    protected static ?string $pluralModelLabel = 'Saalpläne';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 35;

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
                SeatingPlanFields::pdfUpload('path')
                    ->label('Saalplan (PDF)')
                    ->directory(SeatingPlan::DIRECTORY)
                    ->storeFileNamesIn('original_name')
                    ->required()
                    ->getUploadedFileUsing(fn (string $file, ?SeatingPlan $record): ?array => SeatingPlanFields::storedFile(
                        $file,
                        $record?->original_name,
                        $record?->url(),
                    )),
                CheckboxList::make('areas')
                    ->label('Genutzte Bereiche')
                    ->helperText('Werden übernommen, wenn ein Event diesen Saalplan wählt.')
                    ->options(fn (?SeatingPlan $record): array => OptionChoices::for(OptionField::Areas, $record?->areas))
                    ->columns(2),
                CheckboxList::make('seating')
                    ->label('Bestuhlung')
                    ->helperText('Wird ebenso übernommen.')
                    ->options(fn (?SeatingPlan $record): array => OptionChoices::for(OptionField::Seating, $record?->seating)),
                Toggle::make('is_active')
                    ->label('Aktiv')
                    ->helperText('Inaktive Saalpläne stehen bei Events nicht mehr zur Auswahl; Events, die ihn nutzen, behalten ihn.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Saalplan')
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('areas')
                    ->label('Bereiche')
                    ->badge()
                    ->color('gray')
                    ->wrap()
                    ->visibleFrom('md'),
                TextColumn::make('seating')
                    ->label('Bestuhlung')
                    ->badge()
                    ->color('gray')
                    ->wrap()
                    ->visibleFrom('lg'),
                TextColumn::make('events_count')
                    ->label('Events')
                    ->counts('events')
                    ->alignEnd()
                    ->visibleFrom('md'),
                IconColumn::make('is_active')
                    ->label('Aktiv')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->defaultSort('name')
            ->paginated(false)
            ->emptyStateHeading('Noch keine Saalpläne')
            ->recordActions([
                Action::make('pdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (SeatingPlan $record): string => $record->url(), shouldOpenInNewTab: true),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSeatingPlans::route('/'),
        ];
    }
}
