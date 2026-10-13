<?php

namespace App\Filament\Resources\Contacts;

use App\Enums\OptionField;
use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Models\Contact;
use App\Models\FieldOption;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Kontakte ohne Anmeldung (Personen oder Funktionen wie „Hallenmeister“), im
 * Personal der Events wählbar und mit Telefon/E-Mail im Daysheet. Ersetzt seit
 * 14.10.2026 die Mitarbeiter; Rechte über den bisherigen Bereich
 * (Area::AdminMitarbeiter, heute „Kontakte“). Archivieren statt löschen, sobald
 * ein Event auf den Kontakt verweist (ContactPolicy). Neue Kontakte lassen sich
 * auch direkt im Personal-Feld anlegen (AssignmentFields).
 */
class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static ?string $slug = 'kontakte';

    protected static ?string $modelLabel = 'Kontakt';

    protected static ?string $pluralModelLabel = 'Kontakte';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->helperText('Person oder Funktion, z. B. „Hallenmeister“.')
                    ->required()
                    ->maxLength(160),
                TextInput::make('initials')
                    ->label('Kürzel')
                    ->helperText('Für die Projektleitung in der Event-Liste.')
                    ->maxLength(20),
                TextInput::make('phone')
                    ->label('Telefon')
                    ->tel()
                    ->maxLength(50),
                TextInput::make('email')
                    ->label('E-Mail')
                    ->email()
                    ->maxLength(255),
                CheckboxList::make('positions')
                    ->label('Positionen')
                    ->helperText('Für House Rep. braucht es VL, für VfV die Position VfV. Werte pflegen unter Stammdaten › Feldoptionen › Position.')
                    ->options(fn (?Contact $record): array => self::positionOptions($record?->positions))
                    ->columns(3)
                    ->columnSpanFull(),
                Textarea::make('note')
                    ->label('Notiz')
                    ->rows(2)
                    ->columnSpanFull(),
                Toggle::make('is_archived')
                    ->label('Archiviert')
                    ->helperText('Archivierte Kontakte stehen nicht mehr zur Auswahl; an bisherigen Events bleiben sie stehen.'),
            ]);
    }

    /**
     * Gepflegte Positionen plus alles, was schon vergeben ist – damit beim
     * Speichern nichts verloren geht, was es als Option nicht mehr gibt. Auch für
     * die Positionen der Benutzer (UserForm).
     *
     * @param  list<string>|null  $current
     * @return array<string, string>
     */
    public static function positionOptions(?array $current): array
    {
        $options = FieldOption::choices(OptionField::EmployeePosition);
        foreach ($current ?? [] as $position) {
            $options[$position] ??= $position;
        }

        return $options;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
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
            ->defaultSort('name')
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
                Action::make('archive')
                    ->label(fn (Contact $record): string => $record->is_archived ? 'Wiederherstellen' : 'Archivieren')
                    ->icon(fn (Contact $record): Heroicon => $record->is_archived ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedArchiveBox)
                    ->color('gray')
                    ->authorize('update')
                    ->action(fn (Contact $record) => $record->update(['is_archived' => !$record->is_archived])),
                // Nur, solange kein Event auf den Kontakt verweist, siehe ContactPolicy
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageContacts::route('/'),
        ];
    }
}
