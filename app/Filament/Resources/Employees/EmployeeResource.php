<?php

namespace App\Filament\Resources\Employees;

use App\Enums\OptionField;
use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Models\Employee;
use App\Models\FieldOption;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Mitarbeiter werden archiviert (dann nicht mehr wählbar); löschen nur, solange
 * kein Event und kein Benutzerkonto auf sie verweist (EmployeePolicy).
 */
class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static ?string $slug = 'mitarbeiter';

    protected static ?string $modelLabel = 'Mitarbeiter';

    protected static ?string $pluralModelLabel = 'Mitarbeiter';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'last_name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('first_name')
                    ->label('Vorname')
                    ->maxLength(120),
                TextInput::make('last_name')
                    ->label('Nachname')
                    ->required()
                    ->maxLength(120),
                TextInput::make('initials')
                    ->label('Kürzel')
                    ->maxLength(20),
                TextInput::make('phone')
                    ->label('Telefon')
                    ->tel()
                    ->maxLength(50),
                TextInput::make('email')
                    ->label('E-Mail')
                    ->email()
                    ->maxLength(255)
                    ->columnSpanFull(),
                CheckboxList::make('positions')
                    ->label('Positionen')
                    ->helperText('Werte pflegen unter Stammdaten → Feldoptionen → Mitarbeiter-Position.')
                    ->options(fn (?Employee $record): array => self::positionOptions($record))
                    ->columns(3)
                    ->columnSpanFull(),
                Toggle::make('is_archived')
                    ->label('Archiviert')
                    ->helperText('Archivierte Mitarbeiter stehen nicht mehr zur Auswahl; an bisherigen Events bleiben sie stehen.'),
            ]);
    }

    /**
     * Gepflegte Positionen plus alles, was der Mitarbeiter schon hat – damit beim
     * Speichern nichts verloren geht, was es als Option nicht mehr gibt.
     *
     * @return array<string, string>
     */
    public static function positionOptions(?Employee $record): array
    {
        $options = FieldOption::choices(OptionField::EmployeePosition);
        foreach ($record?->positions ?? [] as $position) {
            $options[$position] ??= $position;
        }

        return $options;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('last_name')
            ->columns([
                TextColumn::make('last_name')
                    ->label('Name')
                    ->formatStateUsing(fn (string $state, Employee $record): string => $record->fullName())
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                TextColumn::make('initials')
                    ->label('Kürzel')
                    ->visibleFrom('md'),
                TextColumn::make('positions')
                    ->label('Positionen')
                    ->visibleFrom('md')
                    ->badge(),
                TextColumn::make('phone')
                    ->label('Telefon'),
                TextColumn::make('email')
                    ->label('E-Mail')
                    ->visibleFrom('lg')
                    ->copyable(),
            ])
            ->defaultSort('last_name')
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
                Action::make('archive')
                    ->label(fn (Employee $record): string => $record->is_archived ? 'Wiederherstellen' : 'Archivieren')
                    ->icon(fn (Employee $record): Heroicon => $record->is_archived ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedArchiveBox)
                    ->color('gray')
                    ->authorize('update')
                    ->action(fn (Employee $record) => $record->update(['is_archived' => !$record->is_archived])),
                // Nur, solange nichts auf den Mitarbeiter verweist, siehe EmployeePolicy
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEmployees::route('/'),
        ];
    }
}
