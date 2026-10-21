<?php

namespace App\Filament\Resources\Accounting;

use App\Access\Area;
use App\Filament\Resources\Accounting\Pages\AccountingDashboard;
use App\Filament\Resources\Accounting\Pages\ListAccounting;
use App\Models\Event;
use App\Models\EventFinance;
use App\Models\Promoter;
use App\Models\User;
use App\Support\AccountingChecks;
use App\Support\EventDisplay;
use App\Support\IncomingInvoices;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Buchhaltungsliste: dieselben Events, gesehen von der Buchhaltung. Zugang über
 * den Bereich Buchhaltung, nicht über Events – deshalb eigene Rechteprüfung
 * statt der EventPolicy. Seit 21.10.2026 im Aufbau der Event-Liste (nach Monaten,
 * schmale Zeilen, Filter in einer Zeile, Stil in vcontrol.css), sortierbar per
 * Klick auf die Spalte; ein Klick auf ein Event öffnet das Buchhaltungs-
 * Dashboard (AccountingDashboard), dort bearbeitet die Buchhaltung.
 */
class AccountingResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static ?string $slug = 'buchhaltung';

    protected static ?string $modelLabel = 'Event';

    protected static ?string $pluralModelLabel = 'Buchhaltung';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    /** Gruppe „Finanzen“ mit den Costings, damit die Kopfleiste schmal bleibt (Vorgabe vom 14.10.2026) */
    protected static string|UnitEnum|null $navigationGroup = 'Finanzen';

    protected static ?int $navigationSort = 1;

    /** Die Listen wie bis 21.10.2026 die Reiter: Endabrechnung und Archiv wie in der PHP-Version */
    public const STATES = [
        'offen' => 'Offen',
        'abgeschlossen' => 'Abgeschlossen',
        'endabrechnung' => 'Endabrechnung',
        'archiv' => 'Archiv',
        'alle' => 'Alle',
    ];

    /** Neueste zuerst: was abgeschlossen ist, sucht man eher von hinten */
    public const DESCENDING_STATES = ['abgeschlossen', 'archiv'];

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can(Area::Buchhaltung);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Das Dashboard prüft selbst (AccountingDashboard): lesen mit Buchhaltung, bearbeiten mit Buchhaltung „Bearbeiten“ */
    public static function canView(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        // Sortieren nach einer Spalte der Finanzdaten bzw. des Veranstalters
        $byFinance = fn (string $column) => fn (Builder $query, string $direction): Builder => $query->orderBy(
            EventFinance::query()->select($column)->whereColumn('event_finances.event_id', 'events.id')->limit(1),
            $direction,
        );
        // Sortieren nach einer Prüfung, die in PHP läuft: erst die einen, dann die anderen, je nach Datum
        $byIds = fn (callable $ids) => fn (Builder $query, string $direction): Builder => ($list = $ids()) === []
            ? $query
            : $query->orderByRaw('CASE WHEN events.id IN (' . implode(',', array_map('intval', $list)) . ') THEN 1 ELSE 0 END ' . ($direction === 'desc' ? 'desc' : 'asc'));

        return $table
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Datum')
                    ->formatStateUsing(fn (Event $record): string => EventDisplay::weekday($record->starts_at) . ' ' . $record->starts_at->format('d.m.y')
                        . (EventDisplay::isMultiDay($record) ? ' 📅' : ''))
                    ->tooltip(fn (Event $record): ?string => EventDisplay::isMultiDay($record) ? 'Mehrtägig bis ' . $record->ends_at->format('d.m.Y') : null)
                    ->placeholder('–')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => EventDisplay::statusLabel($state))
                    ->color(fn (?string $state): string => match (EventDisplay::statusKind($state)) {
                        'fraglich' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Veranstaltung')
                    ->limit(40)
                    ->tooltip(fn (Event $record): ?string => AccountingChecks::financeAlert($record)
                        ? 'Finanz-Warnung: Event in höchstens 14 Tagen, Vertrag nicht zurück oder Rate nicht gezahlt'
                        : (mb_strlen((string) $record->title) > 40 ? $record->title : null))
                    ->icon(fn (Event $record): ?Heroicon => AccountingChecks::financeAlert($record) ? Heroicon::ExclamationCircle : null)
                    ->iconColor('danger')
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('promoter_short')
                    ->label('Veranstalter')
                    ->state(fn (Event $record): ?string => EventDisplay::promoterShort($record))
                    ->visibleFrom('md')
                    ->tooltip(fn (Event $record): ?string => $record->promoter?->name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'promoter',
                        fn (Builder $promoter): Builder => $promoter->where('name', 'like', "%{$search}%")->orWhere('short_name', 'like', "%{$search}%"),
                    ))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        Promoter::query()->selectRaw("COALESCE(NULLIF(short_name, ''), name)")->whereColumn('promoters.id', 'events.promoter_id')->limit(1),
                        $direction,
                    )),
                TextColumn::make('va_id')
                    ->label('VA-ID')
                    ->visibleFrom('lg')
                    ->fontFamily(FontFamily::Mono)
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('finance.contract_status')
                    ->label('Vertrag')
                    ->visibleFrom('md')
                    ->sortable(query: $byFinance('contract_status')),
                // Je Rechnung ein Kästchen: „… gezahlt“ grün, sonst „… gestellt“ (AccountingChecks::condensedStatus)
                TextColumn::make('finance.accounting_status')
                    ->label('FIBU-Status')
                    ->visibleFrom('sm')
                    ->state(fn (Event $record): array => AccountingChecks::condensedStatus($record))
                    ->tooltip(fn (Event $record): ?string => implode(', ', (array) ($record->finance?->accounting_status ?? [])) ?: null)
                    ->badge()
                    ->color(fn (string $state): string => preg_match('/(gezahlt|bezahlt)$/iu', trim($state)) === 1 ? 'success' : 'gray')
                    ->wrap()
                    ->sortable(query: $byFinance('accounting_status')),
                TextColumn::make('finance.invoice_numbers')
                    ->label('Rechnungen')
                    ->visibleFrom('lg')
                    ->badge()
                    ->color('gray')
                    ->wrap()
                    ->sortable(query: $byFinance('invoice_numbers')),
                TextColumn::make('incoming_invoices')
                    ->label('Eing.-Rg.')
                    ->visibleFrom('md')
                    ->state(fn (Event $record): ?string => IncomingInvoices::summary($record))
                    ->color(fn (Event $record): string => IncomingInvoices::settled($record) ? 'success' : 'warning')
                    ->tooltip('eingegangen / erwartet')
                    ->alignCenter()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                        'CASE WHEN events.id IN (' . ($settled = Event::query()->incomingInvoicesSettled()->select('events.id'))->toSql() . ') THEN 1 ELSE 0 END ' . ($direction === 'desc' ? 'desc' : 'asc'),
                        $settled->getBindings(),
                    )),
                // Alle verschickten Rechnungen (FIBU-Status „… gestellt“) auch bezahlt („… gezahlt“)
                IconColumn::make('paid')
                    ->label('Bezahlt')
                    ->state(fn (Event $record): bool => AccountingChecks::allPaid($record))
                    ->icon(fn (bool $state): ?Heroicon => $state ? Heroicon::CheckCircle : null)
                    ->color('success')
                    ->size(IconSize::Small)
                    ->tooltip(fn (bool $state): ?string => $state ? 'Alle verschickten Rechnungen sind bezahlt' : null)
                    ->alignCenter()
                    ->sortable(query: $byIds(fn (): array => AccountingChecks::ids('paid'))),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['promoter', 'finance', 'operation', 'incomingInvoices']))
            ->defaultGroup(
                Group::make('month')
                    ->label('Monat')
                    ->titlePrefixedWithLabel(false)
                    ->getKeyFromRecordUsing(fn (Event $record): string => $record->starts_at?->format('Y-m') ?? '')
                    ->getTitleFromRecordUsing(fn (Event $record): string => $record->starts_at ? EventDisplay::month($record->starts_at) : 'Ohne Datum')
                    ->orderQueryUsing(fn (Builder $query, string $direction): Builder => $query->orderBy('starts_at', $direction)),
            )
            ->groupingSettingsHidden()
            ->defaultSort(fn (Builder $query, $livewire): Builder => in_array($livewire->tableFilters['state']['state'] ?? 'offen', self::DESCENDING_STATES, true)
                ? $query->orderByDesc('starts_at')->orderBy('title')
                : $query->orderBy('starts_at')->orderBy('title'))
            ->recordClasses(fn (Event $record): array => [
                'vc-row--fraglich' => EventDisplay::statusKind($record->status) === 'fraglich',
                'vc-row--cancelled' => EventDisplay::statusKind($record->status) === 'cancelled',
            ])
            ->recordUrl(fn (Event $record): string => static::getUrl('dashboard', ['record' => $record]))
            ->filters([
                Filter::make('state')
                    ->schema([
                        Select::make('state')
                            ->label('Liste')
                            ->options(self::STATES)
                            ->default('offen')
                            ->selectablePlaceholder(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['state'] ?? 'offen') {
                        'abgeschlossen' => $query->finalInvoiced(false)->accountingClosed(),
                        'endabrechnung' => $query->finalInvoiced()->archiveReady(false),
                        'archiv' => $query->finalInvoiced()->archiveReady(),
                        'alle' => $query,
                        default => $query->finalInvoiced(false)->accountingClosed(false),
                    })
                    ->indicateUsing(fn (array $data): ?string => ($data['state'] ?? 'offen') === 'offen'
                        ? null
                        : 'Liste: ' . (self::STATES[$data['state']] ?? $data['state'])),
                Filter::make('check')
                    ->schema([
                        Select::make('check')
                            ->label('Prüfen')
                            ->placeholder('Alle')
                            ->options(AccountingChecks::LABELS),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['check'] ?? null)
                        ? $query->whereIn('events.id', AccountingChecks::ids((string) $data['check']))
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => filled($data['check'] ?? null)
                        ? (AccountingChecks::LABELS[$data['check']] ?? null)
                        : null),
                SelectFilter::make('year')
                    ->label('Jahr')
                    ->placeholder('Alle')
                    ->options(fn (): array => Event::query()->whereNotNull('starts_at')->pluck('starts_at')
                        ->map(fn ($date): string => (string) $date->year)->unique()->sortDesc()
                        ->mapWithKeys(fn (string $year): array => [$year => $year])->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereYear('starts_at', (int) $data['value'])
                        : $query),
                SelectFilter::make('promoter')
                    ->label('Veranstalter')
                    ->placeholder('Alle')
                    ->relationship('promoter', 'name')
                    ->searchable()
                    ->preload(),
            ], layout: FiltersLayout::AboveContentCollapsible) // wie die Event-Liste: auf dem Handy hinter dem Filter-Symbol
            ->filtersFormColumns(['default' => 2, 'lg' => 4])
            ->deferFilters(false)
            ->hiddenFilterIndicators()
            ->paginated([50, 100, 'all'])
            ->defaultPaginationPageOption(100)
            ->emptyStateHeading('Keine Events für diese Filter');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccounting::route('/'),
            'dashboard' => AccountingDashboard::route('/{record}'),
        ];
    }
}
