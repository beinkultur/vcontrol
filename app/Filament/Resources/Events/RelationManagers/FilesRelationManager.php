<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Forms\SafeFileUpload;
use App\Filament\Resources\EventFileTags\EventFileTagResource;
use App\Models\EventFile;
use App\Models\EventFileTag;
use App\Models\User;
use App\Support\Uploads;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Planung › Dateien wie in der PHP-Version: neue Version hochladen
 * (Versionsnummer zählt hoch), Anzeigename, löschen. Herunterladen über
 * EventFileController. Seit 19.10.2026: mehrere Dateien auf einmal hochladen
 * (auch per Ziehen), das Tag ist dabei optional – vergeben wird es danach direkt
 * in der Zeile oder für mehrere markierte Dateien („Tag vergeben“). Dateien ohne
 * Tag stehen oben, sonst nach Tag alphabetisch.
 */
class FilesRelationManager extends RelationManager
{
    protected static string $relationship = 'files';

    protected static ?string $title = 'Dateien';

    protected static ?string $modelLabel = 'Datei';

    protected static ?string $pluralModelLabel = 'Dateien';

    /** Höchstens so viele Dateien je Hochladen */
    public const MAX_FILES = 20;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('tag_id')
                    ->label('Tag')
                    ->options(fn (?EventFile $record): array => EventFileTag::options($record?->tag_id))
                    ->required()
                    ->native(false),
                TextInput::make('title')
                    ->label('Anzeigename')
                    ->placeholder(fn (?EventFile $record): string => $record?->original_name ?? 'leer: Dateiname')
                    ->maxLength(255),
                SafeFileUpload::make('upload')
                    ->label(fn (string $operation): string => $operation === 'create' ? 'Datei' : 'Neue Version (optional)')
                    ->helperText(fn (string $operation): string => ($operation === 'create' ? '' : 'Ersetzt die Datei und erhöht die Versionsnummer. ')
                        . 'Max. ' . intdiv(Uploads::MAX_KB, 1024) . ' MB: ' . implode(', ', self::extensions()) . '.')
                    ->disk(EventFile::DISK)
                    ->directory(fn (): string => 'event-files/' . $this->getOwnerRecord()->getKey())
                    ->visibility('private')
                    // Nur frische Uploads – sonst ließe sich per Livewire der Pfad einer fremden Datei unterschieben
                    ->preventFilePathTampering()
                    // Auswahl, Endung, Inhalt und Größe (25 MB)
                    ->allowedExtensions(fn (): array => self::extensions())
                    ->storeFileNamesIn('original_name')
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->columnSpanFull(),
                Toggle::make('hidden_from_externals')
                    ->label('Für Externe verbergen')
                    ->helperText('Freelancer und Gewerke (Extern-Bereich, Daysheet) sehen diese Datei dann nicht – etwa einen Vertrag.')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('tag'))
            // Ohne Tag zuerst (NULL vorn), dann nach Tag und Dateiname
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderBy(EventFileTag::query()->selectRaw('LOWER(name)')->whereColumn('event_file_tags.id', 'event_files.tag_id'))
                ->orderBy('original_name'))
            ->columns([
                // Tag direkt in der Zeile vergeben – auch gleich nach dem Hochladen. „Ohne Tag“
                // bleibt wählbar: als gesperrte Option zeigte der Browser sonst das erste Tag an
                SelectColumn::make('tag_id')
                    ->label('Tag')
                    ->options(fn (EventFile $record): array => EventFileTag::options($record->tag_id))
                    ->placeholder('Ohne Tag')
                    ->rules(fn (EventFile $record): array => ['nullable', Rule::in(array_keys(EventFileTag::options($record->tag_id)))])
                    ->disabled(fn (EventFile $record): bool => !$this->canEdit($record)),
                TextColumn::make('display_name')
                    ->label('Datei')
                    ->state(fn (EventFile $record): string => $record->displayName())
                    ->description(fn (EventFile $record): ?string => filled($record->title) ? $record->original_name : null)
                    ->url(fn (EventFile $record): string => $record->downloadUrl(), shouldOpenInNewTab: true)
                    ->weight(FontWeight::Medium)
                    ->color('primary')
                    ->searchable(['title', 'original_name']),
                IconColumn::make('hidden_from_externals')
                    ->label('Extern')
                    ->visibleFrom('md')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedEyeSlash)
                    ->falseIcon(Heroicon::OutlinedEye)
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->tooltip(fn (EventFile $record): string => $record->hidden_from_externals ? 'Für Externe verborgen' : 'Für Externe sichtbar'),
                TextColumn::make('stand')
                    ->label('Stand')
                    ->state(fn (EventFile $record): string => ($record->uploaded_at?->format('d.m.Y H:i') ?? '–') . ' · v' . $record->version),
                TextColumn::make('size')
                    ->label('Größe')
                    ->visibleFrom('md')
                    ->state(fn (EventFile $record): string => $record->sizeLabel())
                    ->alignEnd(),
                TextColumn::make('created_by_name')
                    ->label('Von')
                    ->visibleFrom('lg')
                    ->description(fn (EventFile $record): ?string => filled($record->updated_by_name) && $record->updated_by_name !== $record->created_by_name
                        ? 'geändert von ' . $record->updated_by_name
                        : null),
            ])
            ->paginated(false)
            ->emptyStateHeading('Noch keine Dateien')
            ->emptyStateDescription('Mehrere auf einmal hochladen, auch per Ziehen – die Tags danach in der Liste vergeben.')
            ->headerActions([
                Action::make('tags')
                    ->label('Tags verwalten')
                    ->link()
                    ->url(fn (): string => EventFileTagResource::getUrl())
                    ->visible(fn (): bool => EventFileTagResource::canViewAny()),
                CreateAction::make()
                    ->label('Dateien hochladen')
                    ->modalHeading('Dateien hochladen')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->createAnother(false)
                    ->schema(fn (): array => self::uploadSchema())
                    ->using(fn (array $data): EventFile => $this->storeUploads($data))
                    ->successNotificationTitle(fn (array $data): string => self::uploadedMessage($data)),
            ])
            ->toolbarActions([
                BulkAction::make('assignTag')
                    ->label('Tag vergeben')
                    ->icon(Heroicon::OutlinedTag)
                    ->modalHeading('Tag für die markierten Dateien')
                    ->modalSubmitActionLabel('Tag vergeben')
                    ->visible(fn (): bool => !$this->isReadOnly() && (Auth::user() instanceof User) && Auth::user()->can('create', EventFile::class))
                    ->authorizeIndividualRecords('update')
                    ->schema([
                        Select::make('tag_id')
                            ->label('Tag')
                            ->options(fn (): array => EventFileTag::options())
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        // Einzeln, damit das Änderungsprotokoll jede Datei sieht
                        $records->each(fn (EventFile $file) => $file->update(['tag_id' => (int) $data['tag_id']]));
                    })
                    ->deselectRecordsAfterCompletion()
                    ->successNotificationTitle('Tag vergeben'),
            ])
            ->recordActions([
                // Als Symbole mit Tooltip – die Tabelle steht im geboxten Workspace
                Action::make('download')
                    ->label('Herunterladen')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->iconButton()
                    ->url(fn (EventFile $record): string => $record->downloadUrl(), shouldOpenInNewTab: true),
                EditAction::make()
                    ->iconButton()
                    ->using(function (EventFile $record, array $data): EventFile {
                        $attributes = [
                            'tag_id' => $data['tag_id'],
                            'title' => self::title($data['title'] ?? null),
                            'hidden_from_externals' => (bool) ($data['hidden_from_externals'] ?? false),
                        ];
                        $oldPath = null;
                        if (filled($data['upload'] ?? null)) {
                            $oldPath = $record->path;
                            $attributes += self::fileAttributes($data) + ['version' => $record->version + 1, 'uploaded_at' => now()];
                        }
                        $record->update($attributes);
                        if ($oldPath !== null && $oldPath !== $record->path) {
                            Storage::disk(EventFile::DISK)->delete($oldPath);
                        }

                        return $record;
                    }),
                DeleteAction::make()->iconButton(),
            ]);
    }

    /**
     * Mehrere Dateien auf einmal (auch per Ziehen), ein Tag für alle und „Für
     * Externe verbergen“ sind optional – Tags lassen sich danach in der Liste
     * vergeben.
     *
     * @return list<\Filament\Schemas\Components\Component>
     */
    private static function uploadSchema(): array
    {
        return [
            SafeFileUpload::make('uploads')
                ->label('Dateien')
                ->helperText('Mehrere auf einmal, auch per Ziehen. Je Datei max. ' . intdiv(Uploads::MAX_KB, 1024) . ' MB: ' . implode(', ', self::extensions()) . '.')
                ->multiple()
                ->maxFiles(self::MAX_FILES)
                ->disk(EventFile::DISK)
                ->directory(fn (RelationManager $livewire): string => 'event-files/' . $livewire->getOwnerRecord()->getKey())
                ->visibility('private')
                // Nur frische Uploads – sonst ließe sich per Livewire der Pfad einer fremden Datei unterschieben
                ->preventFilePathTampering()
                // Auswahl, Endung, Inhalt und Größe je Datei
                ->allowedExtensions(fn (): array => self::extensions())
                ->storeFileNamesIn('original_names')
                ->required(),
            Select::make('tag_id')
                ->label('Tag für alle (optional)')
                ->helperText('Leer lassen und die Tags danach in der Liste vergeben.')
                ->options(fn (): array => EventFileTag::options())
                ->native(false),
            Toggle::make('hidden_from_externals')
                ->label('Für Externe verbergen')
                ->helperText('Freelancer und Gewerke (Extern-Bereich, Daysheet) sehen diese Dateien dann nicht – etwa einen Vertrag.'),
        ];
    }

    /** Je hochgeladene Datei ein Eintrag; zurück kommt der letzte. @param  array<string, mixed>  $data */
    private function storeUploads(array $data): EventFile
    {
        $names = (array) ($data['original_names'] ?? []);
        $file = null;
        foreach ((array) $data['uploads'] as $path) {
            $file = $this->getOwnerRecord()->files()->create([
                'tag_id' => filled($data['tag_id'] ?? null) ? (int) $data['tag_id'] : null,
                'hidden_from_externals' => (bool) ($data['hidden_from_externals'] ?? false),
                ...self::fileAttributes(['upload' => $path, 'original_name' => $names[$path] ?? null]),
                'version' => 1,
            ]);
        }

        return $file;
    }

    /** „3 Dateien hochgeladen – Tags in der Liste vergeben“ @param  array<string, mixed>  $data */
    private static function uploadedMessage(array $data): string
    {
        $count = count((array) ($data['uploads'] ?? []));

        return ($count === 1 ? '1 Datei' : $count . ' Dateien') . ' hochgeladen'
            . (blank($data['tag_id'] ?? null) ? ' – Tags in der Liste vergeben' : '');
    }

    /** Wie in der PHP-Version; Archive (zip, rar, 7z) nur für Admins. @return list<string> */
    public static function extensions(): array
    {
        $user = Auth::user();
        $isAdmin = $user instanceof User && $user->access()->isSuper();

        return $isAdmin ? [...EventFile::EXTENSIONS, ...EventFile::ARCHIVE_EXTENSIONS] : EventFile::EXTENSIONS;
    }

    /** @param  array<string, mixed>  $data @return array<string, mixed> */
    private static function fileAttributes(array $data): array
    {
        $path = is_array($data['upload']) ? reset($data['upload']) : $data['upload'];
        $name = $data['original_name'] ?? null;
        $name = is_array($name) ? reset($name) : $name;
        $disk = Storage::disk(EventFile::DISK);

        return [
            'path' => $path,
            'original_name' => filled($name) ? $name : basename((string) $path),
            'mime_type' => $disk->mimeType($path) ?: null,
            'size' => $disk->size($path),
        ];
    }

    private static function title(?string $title): ?string
    {
        $title = trim((string) $title);

        return $title === '' ? null : $title;
    }
}
