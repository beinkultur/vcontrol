<?php

namespace App\Filament\Support;

use App\Access\Area;
use App\Filament\Resources\Events\EventResource;
use App\Models\Damage;
use App\Models\Event;
use App\Models\User;
use App\Support\DamageNotifier;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Auth;

/**
 * Formular, Ansicht, Spalten und Aktionen der Schäden – gemeinsam für den
 * Reiter im Event (Durchführung › Schäden) und die Übersicht unter Protokolle.
 */
final class DamageFields
{
    /** Wie DamageRepository::MAX_PHOTOS_PER_DAMAGE der PHP-Version. */
    public const MAX_PHOTOS = 20;

    /** @return list<\Filament\Schemas\Components\Component> */
    public static function form(bool $withEvent): array
    {
        return array_values(array_filter([
            $withEvent
                ? Select::make('event_id')
                    ->label('Event-Zuordnung (optional)')
                    ->placeholder('allgemeiner Schaden, ohne Event')
                    ->relationship('event', 'title', fn ($query) => $query->orderByDesc('starts_at'))
                    ->getOptionLabelFromRecordUsing(fn (Event $event): string => ($event->starts_at?->format('d.m.Y') ?? '–') . ' · ' . $event->title)
                    ->searchable(['title', 'va_id'])
                    ->columnSpanFull()
                : null,
            DateTimePicker::make('recorded_at')
                ->label('Zeitpunkt')
                ->seconds(false)
                ->default(fn () => now())
                ->required(),
            Textarea::make('description')
                ->label('Beschreibung des Schadens')
                ->required()
                ->rows(4)
                ->maxLength(5000)
                ->columnSpanFull(),
            FileUpload::make('photos')
                ->label('Fotos (optional)')
                ->helperText('JPG, PNG, WebP oder GIF, bis ' . self::MAX_PHOTOS . ' Fotos zu je 15 MB.')
                ->image()
                ->acceptedFileTypes(Damage::PHOTO_TYPES)
                ->multiple()
                ->maxFiles(self::MAX_PHOTOS)
                ->maxSize(15 * 1024)
                ->disk(Damage::DISK)
                ->directory(Damage::PHOTO_DIRECTORY)
                ->visibility('private')
                // Nur die eigenen Fotos: sonst ließe sich per Livewire ein fremder Pfad unterschieben
                ->preventFilePathTampering()
                ->storeFileNamesIn('photo_names')
                ->reorderable()
                ->columnSpanFull(),
            // Wie im Bearbeiten-Formular der PHP-Version; beim Melden ist ein Schaden immer offen
            Toggle::make('is_fixed')
                ->label('Schaden behoben')
                ->visibleOn('edit'),
        ]));
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    public static function infolist(bool $withEvent): array
    {
        return array_values(array_filter([
            $withEvent ? TextEntry::make('event.title')->label('Event')->placeholder('allgemein') : null,
            TextEntry::make('recorded_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i'),
            TextEntry::make('recorder')->label('Aufgenommen durch')->state(fn (Damage $record): ?string => $record->recorderName())->placeholder('–'),
            TextEntry::make('status')->label('Status')->state(fn (Damage $record): string => $record->is_fixed ? 'behoben' : 'offen')
                ->badge()->color(fn (Damage $record): string => $record->is_fixed ? 'success' : 'danger'),
            TextEntry::make('description')->label('Beschreibung')->columnSpanFull(),
            ImageEntry::make('photo_urls')->label('Fotos')->state(fn (Damage $record): array => $record->photoUrls())
                ->imageHeight(160)->placeholder('Keine Fotos')->columnSpanFull(),
        ]));
    }

    /** @return list<\Filament\Tables\Columns\Column> */
    public static function columns(bool $withEvent): array
    {
        return array_values(array_filter([
            TextColumn::make('recorded_at')->label('Datum')->dateTime('d.m.Y H:i')->sortable(),
            $withEvent
                ? TextColumn::make('event.title')->label('Event')->placeholder('allgemein')
                    ->description(fn (Damage $record): ?string => $record->event?->starts_at?->format('d.m.Y'))
                    ->url(fn (Damage $record): ?string => $record->event === null ? null
                        : EventResource::getUrl(EventResource::canEdit($record->event) ? 'edit' : 'view', ['record' => $record->event]) . '?phase=durchfuehrung&bereich=schaeden')
                    ->searchable()
                : null,
            TextColumn::make('recorder')->label('Aufgenommen durch')->state(fn (Damage $record): ?string => $record->recorderName())->placeholder('–'),
            TextColumn::make('description')->label('Beschreibung')->limit(80)->wrap()->searchable(),
            ImageColumn::make('photo_urls')->label('Fotos')->state(fn (Damage $record): array => $record->photoUrls())
                ->imageHeight(32)->stacked()->limit(3)->limitedRemainingText(),
            TextColumn::make('status')->label('Status')->state(fn (Damage $record): string => $record->is_fixed ? 'behoben' : 'offen')
                ->badge()->color(fn (Damage $record): string => $record->is_fixed ? 'success' : 'danger'),
        ]));
    }

    /** „Als behoben markieren“: Event-Operationen oder die Buchhaltung wie in der PHP-Version. */
    public static function fixAction(): Action
    {
        return Action::make('fix')
            ->label(fn (Damage $record): string => $record->is_fixed ? 'Wieder offen' : 'Als behoben markieren')
            ->icon(fn (Damage $record): Heroicon => $record->is_fixed ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedCheckCircle)
            ->color(fn (Damage $record): string => $record->is_fixed ? 'gray' : 'success')
            ->iconButton()
            ->visible(fn (): bool => self::canFix())
            ->action(fn (Damage $record) => $record->update(['is_fixed' => !$record->is_fixed]));
    }

    public static function canFix(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ($user->access()->canEdit(Area::EventsOperations) || $user->access()->canEdit(Area::Buchhaltung));
    }

    /** @param  array<string, mixed>  $data */
    public static function create(array $data, ?int $eventId = null): Damage
    {
        $damage = Damage::create([
            'event_id' => $eventId ?? ($data['event_id'] ?? null),
            'recorded_at' => $data['recorded_at'],
            'description' => trim((string) $data['description']),
            'photos' => array_values($data['photos'] ?? []),
            'photo_names' => $data['photo_names'] ?? null,
        ]);
        DamageNotifier::notify($damage);

        return $damage;
    }
}
