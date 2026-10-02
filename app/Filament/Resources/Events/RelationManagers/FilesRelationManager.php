<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Resources\EventFileTags\EventFileTagResource;
use App\Models\EventFile;
use App\Models\EventFileTag;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Planung › Dateien wie in der PHP-Version: hochladen mit Tag und Anzeigename,
 * neue Version hochladen (Versionsnummer zählt hoch), löschen. Herunterladen
 * über EventFileController.
 */
class FilesRelationManager extends RelationManager
{
    protected static string $relationship = 'files';

    protected static ?string $title = 'Dateien';

    protected static ?string $modelLabel = 'Datei';

    protected static ?string $pluralModelLabel = 'Dateien';

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
                FileUpload::make('upload')
                    ->label(fn (string $operation): string => $operation === 'create' ? 'Datei' : 'Neue Version (optional)')
                    ->helperText(fn (string $operation): string => ($operation === 'create' ? '' : 'Ersetzt die Datei und erhöht die Versionsnummer. ')
                        . 'Max. 15 MB: ' . implode(', ', self::extensions()) . '.')
                    ->disk(EventFile::DISK)
                    ->directory(fn (): string => 'event-files/' . $this->getOwnerRecord()->getKey())
                    ->visibility('private')
                    // Nur frische Uploads – sonst ließe sich per Livewire der Pfad einer fremden Datei unterschieben
                    ->preventFilePathTampering()
                    ->maxSize(EventFile::MAX_KB)
                    ->rule(fn (): string => 'extensions:' . implode(',', self::extensions()))
                    // Die Endung allein sagt nichts: HTML oder SVG als „.pdf“ wäre ausführbarer Inhalt
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if ($value instanceof UploadedFile && in_array(strtolower((string) $value->getMimeType()), EventFile::BLOCKED_TYPES, true)) {
                            $fail('Webseiten, SVG-Grafiken und Skripte sind als Datei nicht erlaubt.');
                        }
                    })
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
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderBy(EventFileTag::query()->select('sort_order')->whereColumn('event_file_tags.id', 'event_files.tag_id'))
                ->orderBy('original_name'))
            ->columns([
                TextColumn::make('tag.name')
                    ->label('Tag')
                    ->badge()
                    ->color('gray'),
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
                    ->state(fn (EventFile $record): string => $record->sizeLabel())
                    ->alignEnd(),
                TextColumn::make('created_by_name')
                    ->label('Von')
                    ->description(fn (EventFile $record): ?string => filled($record->updated_by_name) && $record->updated_by_name !== $record->created_by_name
                        ? 'geändert von ' . $record->updated_by_name
                        : null),
            ])
            ->paginated(false)
            ->emptyStateHeading('Noch keine Dateien')
            ->headerActions([
                Action::make('tags')
                    ->label('Tags verwalten')
                    ->link()
                    ->url(fn (): string => EventFileTagResource::getUrl())
                    ->visible(fn (): bool => EventFileTagResource::canViewAny()),
                CreateAction::make()
                    ->label('Datei hochladen')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->using(fn (array $data): EventFile => $this->getOwnerRecord()->files()->create([
                        'tag_id' => $data['tag_id'],
                        'title' => self::title($data['title'] ?? null),
                        'hidden_from_externals' => (bool) ($data['hidden_from_externals'] ?? false),
                        ...self::fileAttributes($data),
                        'version' => 1,
                    ])),
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
