<?php

namespace App\Filament\Resources\Events\Tables;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Support\StagePodests;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Wie die Event-Liste der PHP-Version: voreingestellt offen und ab heute. */
class EventsTable
{
    public const TIME_OPTIONS = ['future' => 'Ab heute', 'past' => 'Vergangen', 'all' => 'Alle'];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Datum')
                    ->date('D, d.m.Y')
                    ->description(fn (Event $record): ?string => $record->starts_at?->format('H:i') === '00:00' ? null : $record->starts_at?->format('H:i') . ' Uhr')
                    ->color(fn (Event $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->tooltip(fn (Event $record): ?string => $record->isOverdue() ? 'Vergangen, aber noch nicht abgeschlossen' : null)
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Titel')
                    ->description(fn (Event $record): ?string => $record->promoter?->name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('va_id')
                    ->label('VA-ID')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        Event::STATUS_CANCELLED => 'danger',
                        'bestätigt' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('event_type1')
                    ->label('Kategorie')
                    ->description(fn (Event $record): ?string => $record->event_type2)
                    ->toggleable(),
                TextColumn::make('seating')
                    ->label('Bestuhlung')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('stage_summary')
                    ->label('Bühne')
                    ->state(fn (Event $record): string => StagePodests::summary($record->stage)['text'])
                    ->color(fn (Event $record): ?string => StagePodests::summary($record->stage)['alert'] ? 'danger' : null)
                    ->tooltip('Maße, Höhe, Podeste – rot: andere Höhe als 1,4 m oder mehr Podeste als im Bestand')
                    ->toggleable(),
                TextColumn::make('pax_expected')
                    ->label('PAX erw.')
                    ->numeric(thousandsSeparator: '.')
                    ->alignEnd()
                    ->toggleable(),
                IconColumn::make('closed')
                    ->label('Abgeschl.')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['promoter', 'stage']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('starts_at')->orderBy('title'))
            ->filters([
                Filter::make('time')
                    ->label('Zeitraum')
                    ->schema([
                        Select::make('time')
                            ->label('Zeitraum')
                            ->options(self::TIME_OPTIONS)
                            ->default('future')
                            ->selectablePlaceholder(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['time'] ?? 'future') {
                        'past' => $query->whereDate('starts_at', '<', today()),
                        'all' => $query,
                        default => $query->whereDate('starts_at', '>=', today()),
                    })
                    ->indicateUsing(fn (array $data): ?string => ($data['time'] ?? 'future') === 'all' ? null : self::TIME_OPTIONS[$data['time'] ?? 'future']),
                SelectFilter::make('year')
                    ->label('Jahr')
                    ->options(fn (): array => self::years())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereYear('starts_at', (int) $data['value'])
                        : $query),
                SelectFilter::make('promoter')
                    ->label('Veranstalter')
                    ->relationship('promoter', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(fn (): array => Event::query()->whereNotNull('status')->distinct()->orderBy('status')->pluck('status', 'status')->all()),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->recordActions([
                // Ansehen nur für Leserollen – wer bearbeiten darf, landet im Workspace
                ViewAction::make()->hidden(fn (Event $record): bool => EventResource::canEdit($record)),
                EditAction::make(),
            ]);
    }

    /**
     * Jahre mit Events, neueste zuerst. In PHP statt YEAR(), damit es auch
     * unter SQLite (Tests) läuft.
     *
     * @return array<string, string>
     */
    private static function years(): array
    {
        return Event::query()->whereNotNull('starts_at')->pluck('starts_at')
            ->map(fn ($date): string => (string) $date->year)
            ->unique()
            ->sortDesc()
            ->mapWithKeys(fn (string $year): array => [$year => $year])
            ->all();
    }
}
