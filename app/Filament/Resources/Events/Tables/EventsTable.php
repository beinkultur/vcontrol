<?php

namespace App\Filament\Resources\Events\Tables;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Support\EventDisplay;
use App\Support\StagePodests;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Die Event-Liste – Herzstück der App. Spalten, Reihenfolge und Filter wie in
 * der PHP-Version: nach Monaten gruppiert, voreingestellt offen und ab heute,
 * schmale Zeilen (Stil in public/css/vcontrol.css), ein Klick öffnet das Event.
 */
class EventsTable
{
    public const STATE_OPTIONS = ['open' => 'Offen', 'closed' => 'Abgeschlossen', 'all' => 'Alle'];

    public const TIME_OPTIONS = ['future' => 'Zukünftige', 'past' => 'Vergangene', 'all' => 'Alle'];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Datum')
                    ->formatStateUsing(fn (Event $record): string => EventDisplay::weekday($record->starts_at) . ' ' . $record->starts_at->format('d.m.')
                        . (EventDisplay::isMultiDay($record) ? ' 📅' : ''))
                    ->tooltip(fn (Event $record): ?string => EventDisplay::isMultiDay($record) ? 'Mehrtägig bis ' . $record->ends_at->format('d.m.Y') : null)
                    ->placeholder('–'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => EventDisplay::statusLabel($state))
                    ->color(fn (?string $state): string => match (EventDisplay::statusKind($state)) {
                        'fraglich' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('title')
                    ->label('Veranstaltung')
                    ->limit(40)
                    ->tooltip(fn (Event $record): ?string => mb_strlen((string) $record->title) > 40 ? $record->title : null)
                    ->icon(fn (Event $record): ?Heroicon => $record->hasFinanceAlert() ? Heroicon::ExclamationCircle : null)
                    ->iconColor('danger')
                    ->weight(FontWeight::Medium)
                    ->searchable(),
                TextColumn::make('promoter_short')
                    ->label('Veranstalter')
                    ->state(fn (Event $record): ?string => EventDisplay::promoterShort($record))
                    ->tooltip(fn (Event $record): ?string => $record->promoter?->name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'promoter',
                        fn (Builder $promoter): Builder => $promoter->where('name', 'like', "%{$search}%")->orWhere('short_name', 'like', "%{$search}%"),
                    )),
                TextColumn::make('category')
                    ->label('VA-Kat.')
                    ->state(fn (Event $record): ?string => $record->event_type2 ?: $record->event_type1)
                    ->tooltip(fn (Event $record): ?string => collect([$record->event_type1, $record->event_type2])->filter()->implode(' / ') ?: null)
                    ->color('gray'),
                TextColumn::make('project_lead')
                    ->label('PL')
                    ->state(fn (Event $record): ?string => EventDisplay::projectLeadShort($record))
                    ->tooltip(fn (Event $record): ?string => EventDisplay::projectLeadName($record)),
                TextColumn::make('pax_expected')
                    ->label('PAX')
                    ->numeric(thousandsSeparator: '.')
                    ->alignEnd(),
                TextColumn::make('seated')
                    ->label('Best.')
                    ->state(fn (Event $record): ?string => EventDisplay::fullySeated($record) ? '🪑' : null)
                    ->tooltip(fn (Event $record): ?string => EventDisplay::fullySeated($record) ? 'Bestuhlt – Stühle extern anmieten' : null)
                    ->alignCenter(),
                // Wie in der PHP-Version: gelb bei anderer Höhe als 1,4 m, rot bei mehr Podesten als im Bestand
                TextColumn::make('stage_summary')
                    ->label('Bühne')
                    ->state(fn (Event $record): string => StagePodests::summary($record->stage)['text'])
                    ->badge(fn (Event $record): bool => StagePodests::summary($record->stage)['alert'])
                    ->color(fn (Event $record): string => match (true) {
                        StagePodests::summary($record->stage)['podests'] => 'danger',
                        StagePodests::summary($record->stage)['height'] => 'warning',
                        default => 'gray',
                    })
                    ->tooltip('Breite × Tiefe, Höhe, Podeste'),
                // Daysheet verschickt (Link nicht gesperrt): nur dann ein grüner Haken
                IconColumn::make('daysheet_sent_at')
                    ->label(new HtmlString('<span title="Daysheet versendet">DS</span>'))
                    ->icon(fn (?string $state): ?Heroicon => filled($state) ? Heroicon::CheckCircle : null)
                    ->color('success')
                    ->size(IconSize::Small)
                    ->tooltip(fn (?string $state): ?string => filled($state)
                        ? 'Daysheet versendet am ' . Carbon::parse($state)->format('d.m.Y, H:i') . ' Uhr'
                        : null)
                    ->alignCenter(),
                TextColumn::make('va_id')
                    ->label('VA-ID')
                    ->fontFamily(FontFamily::Mono)
                    ->color('gray')
                    ->searchable(),
                ViewColumn::make('progress')
                    ->label('Fortschritt')
                    ->view('filament.events.list-progress'),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['promoter', 'finance', 'pr', 'schedule', 'checklist', 'stage', 'services', 'assignments.assignee'])
                ->withMax(['daysheets as daysheet_sent_at' => fn (Builder $daysheets): Builder => $daysheets->whereNull('revoked_at')], 'created_at'))
            ->defaultGroup(
                Group::make('month')
                    ->label('Monat')
                    ->titlePrefixedWithLabel(false)
                    ->getKeyFromRecordUsing(fn (Event $record): string => $record->starts_at?->format('Y-m') ?? '')
                    ->getTitleFromRecordUsing(fn (Event $record): string => $record->starts_at ? EventDisplay::month($record->starts_at) : 'Ohne Datum')
                    ->orderQueryUsing(fn (Builder $query, string $direction): Builder => $query->orderBy('starts_at', $direction)),
            )
            ->groupingSettingsHidden()
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('starts_at')->orderBy('title'))
            ->recordClasses(fn (Event $record): array => [
                'vc-row--fraglich' => EventDisplay::statusKind($record->status) === 'fraglich',
                'vc-row--cancelled' => EventDisplay::statusKind($record->status) === 'cancelled',
                'vc-row--past' => $record->starts_at?->lt(today()) ?? false,
            ])
            ->recordUrl(fn (Event $record): string => EventResource::getUrl(EventResource::canEdit($record) ? 'edit' : 'view', ['record' => $record]))
            ->filters([
                Filter::make('state')
                    ->schema([
                        Select::make('state')
                            ->label('Status')
                            ->options(self::STATE_OPTIONS)
                            ->default('open')
                            ->selectablePlaceholder(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['state'] ?? 'open') {
                        'closed' => $query->where('closed', true),
                        'all' => $query,
                        default => $query->where('closed', false),
                    }),
                Filter::make('time')
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
                    }),
                SelectFilter::make('year')
                    ->label('Jahr')
                    ->placeholder('Alle')
                    ->options(fn (): array => self::years())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereYear('starts_at', (int) $data['value'])
                        : $query),
                SelectFilter::make('promoter')
                    ->label('Veranstalter')
                    ->placeholder('Alle')
                    ->relationship('promoter', 'name')
                    ->searchable()
                    ->preload(),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->deferFilters(false)
            ->hiddenFilterIndicators()
            ->paginated([50, 100, 'all'])
            ->defaultPaginationPageOption(100)
            ->emptyStateHeading('Keine Events für diese Filter');
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
