<?php

namespace App\Filament\Pages;

use App\Access\Area;
use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Meters\MeterResource;
use App\Models\Event;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\User;
use App\Support\MeterReadings;
use App\Support\MeterStats;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Zählerstände (seit 15.10.2026, in der PHP-Version /zaehler): je Zähler die
 * Kennzahlen des Jahres, Verbrauch je Monat gegen das Vorjahr, je Event (Zähler
 * „je Event“) und die Liste der Stände. Freie Ablesungen erfasst, wer im
 * Bereich „Zählerstände“ bearbeiten darf; Stände eines Events pflegt das Event.
 * Welche Zähler es gibt, steht unter Stammdaten › Zähler (MeterResource).
 */
class Meters extends Page implements HasTable
{
    use BoxedPage;
    use InteractsWithTable;

    /** Gleiche Adresse wie in der PHP-Version */
    protected static ?string $slug = 'zaehler';

    protected static ?string $title = 'Zählerstände';

    protected static ?string $navigationLabel = 'Zählerstände';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Protokolle';

    protected static ?int $navigationSort = 35;

    protected string $view = 'filament.pages.meters';

    #[Url(as: 'zaehler')]
    public ?string $zaehler = null;

    #[Url(as: 'jahr')]
    public ?string $jahr = null;

    private ?Meter $selected = null;

    private bool $resolved = false;

    private ?MeterStats $stats = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can(Area::Zaehler);
    }

    /** Gewählter Zähler, sonst der erste aktive, sonst der erste überhaupt */
    public function meter(): ?Meter
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $this->selected = (ctype_digit((string) $this->zaehler) ? Meter::query()->find((int) $this->zaehler) : null)
                ?? Meter::query()->where('is_active', true)->ordered()->first()
                ?? Meter::query()->ordered()->first();
        }

        return $this->selected;
    }

    public function year(): int
    {
        $year = (int) $this->jahr;

        return $year >= 2000 && $year <= now()->year ? $year : now()->year;
    }

    private function stats(): ?MeterStats
    {
        $meter = $this->meter();

        return $meter === null ? null : ($this->stats ??= MeterStats::for($meter));
    }

    /** Nach Erfassen, Ändern, Löschen: neu rechnen, Kennzahlen und Diagramme ziehen nach */
    private function readingsChanged(): void
    {
        $this->stats = null;
        $this->dispatch('meter-readings-changed');
    }

    public function getSubheading(): ?string
    {
        $meter = $this->meter();
        if ($meter === null) {
            return null;
        }

        return collect([
            $meter->name . ' in ' . $meter->unit,
            filled($meter->number) ? 'Zählernummer ' . $meter->number : null,
            $meter->location,
            $meter->per_event ? 'je Event abgelesen (Durchführung › Betrieb)' : null,
            $meter->is_active ? null : 'inaktiv',
        ])->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Eigene Seite ohne Resource: Filament prüft die Policy hier nicht von selbst
            CreateAction::make('createReading')
                ->model(MeterReading::class)
                ->authorize('create')
                ->label('Stand erfassen')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Stand erfassen')
                ->modalWidth('lg')
                ->visible(fn (): bool => Meter::query()->where('is_active', true)->exists())
                ->schema($this->readingSchema(create: true))
                ->successNotificationTitle('Stand erfasst')
                ->after(fn () => $this->readingsChanged()),
            Action::make('manageMeters')
                ->label('Zähler verwalten')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->visible(fn (): bool => MeterResource::canAccess())
                ->url(fn (): string => MeterResource::getUrl()),
        ];
    }

    /**
     * Freie Ablesung: Zähler (nur beim Erfassen), Zeitpunkt, Stand, Zählerwechsel,
     * Notiz. Der Stand muss zwischen seine Nachbarn passen (MeterReadings::conflict).
     *
     * @return list<\Filament\Schemas\Components\Component>
     */
    private function readingSchema(bool $create): array
    {
        $meterOf = fn (Get $get, ?MeterReading $record): ?Meter => $record?->meter
            ?? (filled($get('meter_id')) ? Meter::query()->find((int) $get('meter_id')) : null);

        return array_values(array_filter([
            $create ? Select::make('meter_id')
                ->label('Zähler')
                ->options(fn (): array => Meter::query()->where('is_active', true)->ordered()->pluck('name', 'id')->all())
                ->default(fn (): ?int => $this->meter()?->is_active ? $this->meter()->id : null)
                ->required()
                ->live() : null,
            DateTimePicker::make('read_at')
                ->label('Abgelesen am')
                ->seconds(false)
                ->default(fn () => now()->startOfMinute())
                ->maxDate(fn () => now()->endOfDay())
                ->required(),
            TextInput::make('value')
                ->label('Stand')
                ->numeric()
                ->minValue(0)
                ->maxValue(MeterReadings::MAX_VALUE)
                ->step('any')
                ->required()
                ->suffix(fn (Get $get, ?MeterReading $record): ?string => $meterOf($get, $record)?->unit)
                ->formatStateUsing(fn (mixed $state): ?string => $state === null ? null : MeterReadings::plain($state))
                ->rule(fn (Get $get, ?MeterReading $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $meterOf): void {
                    $meter = $meterOf($get, $record);
                    if ($meter === null || !is_numeric($value) || blank($get('read_at'))) {
                        return;
                    }
                    $message = MeterReadings::conflict($meter, Carbon::parse($get('read_at')), (float) $value, (bool) $get('restart'), $record !== null ? [$record->id] : []);
                    if ($message !== null) {
                        $fail($message);
                    }
                }),
            Toggle::make('restart')
                ->label('Zählerwechsel')
                ->helperText('Neuer oder zurückgesetzter Zähler: Der Stand beginnt neu, seit dem vorigen zählt kein Verbrauch. Den letzten Stand des alten Zählers vorher als eigene Ablesung erfassen.'),
            Textarea::make('note')
                ->label('Notiz')
                ->rows(2)
                ->maxLength(1000),
        ]));
    }

    public function table(Table $table): Table
    {
        $year = $this->year();
        $unit = (string) $this->meter()?->unit;
        $withEvents = $this->meter()?->readings()->whereNotNull('event_id')->exists() ?? false;

        return $table
            ->query(fn (): Builder => MeterReading::query()
                ->where('meter_id', $this->meter()?->id ?? 0)
                ->where('read_at', '>=', Carbon::create($year, 1, 1))
                ->where('read_at', '<', Carbon::create($year + 1, 1, 1))
                ->with('event'))
            ->heading('Stände ' . $year)
            ->description('Stände aus Events pflegt das Event unter Durchführung › Betrieb.')
            ->columns([
                TextColumn::make('read_at')
                    ->label('Abgelesen')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('value')
                    ->label('Stand')
                    ->formatStateUsing(fn (mixed $state): string => MeterStats::reading($state, $unit))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('delta')
                    ->label('Seit vorigem')
                    ->state(fn (MeterReading $record): string => $this->deltaText($record, $unit))
                    ->color(fn (MeterReading $record): ?string => match (true) {
                        $record->restart => 'gray',
                        ($this->stats()?->delta($record) ?? 0) < 0 => 'danger',
                        default => null,
                    })
                    ->alignEnd(),
                TextColumn::make('source')
                    ->label('Quelle')
                    ->state(fn (MeterReading $record): string => $record->sourceLabel())
                    ->badge()
                    ->color(fn (MeterReading $record): string => $record->isFromEvent() ? 'info' : ($record->restart ? 'warning' : 'gray')),
                TextColumn::make('event.title')
                    ->label('Event')
                    ->url(fn (MeterReading $record): ?string => $this->eventUrl($record->event))
                    ->color(fn (MeterReading $record): ?string => $this->eventUrl($record->event) !== null ? 'primary' : null)
                    ->wrap()
                    ->visible($withEvents)
                    ->visibleFrom('md'),
                TextColumn::make('note')
                    ->label('Notiz')
                    ->limit(80)
                    ->wrap()
                    ->visibleFrom('lg'),
                TextColumn::make('created_by_name')
                    ->label('Erfasst von')
                    ->visibleFrom('xl'),
            ])
            ->defaultSort('read_at', 'desc')
            ->recordActions([
                // Nur freie Ablesungen, siehe MeterReadingPolicy
                EditAction::make()
                    ->authorize('update')
                    ->modalHeading('Stand ändern')
                    ->modalWidth('lg')
                    ->schema($this->readingSchema(create: false))
                    ->after(fn () => $this->readingsChanged()),
                DeleteAction::make()
                    ->authorize('delete')
                    ->after(fn () => $this->readingsChanged()),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Keine Stände ' . $year)
            ->emptyStateDescription($this->meter() === null ? 'Erst einen Zähler anlegen (Stammdaten › Zähler).' : null);
    }

    private function deltaText(MeterReading $reading, string $unit): string
    {
        if ($reading->restart) {
            return 'Zählerwechsel';
        }
        $delta = $this->stats()?->delta($reading);
        if ($delta === null) {
            return '–';
        }

        return ($delta < 0 ? 'Rücksprung ' : '') . MeterStats::amount($delta, $unit);
    }

    /** Zum Event, wenn der Benutzer es sehen darf – in Durchführung › Betrieb */
    public function eventUrl(?Event $event): ?string
    {
        if ($event === null || !EventResource::canView($event)) {
            return null;
        }

        return EventResource::getUrl(EventResource::canEdit($event) ? 'edit' : 'view', ['record' => $event]) . '?phase=durchfuehrung&bereich=betrieb';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $meter = $this->meter();
        $year = $this->year();
        $stats = $this->stats();
        $years = $stats?->years() ?? [];
        $first = min($years[0] ?? $year, $year);

        $months = [];
        if ($stats !== null) {
            $current = $stats->months($year);
            $previous = $stats->months($year - 1);
            foreach (MeterStats::MONTHS as $index => $label) {
                // Angebrochene Monate (erst bis 13.10. abgelesen …) nicht in Prozent vergleichen
                $note = $stats->partialMonth($year, $index + 1);
                $previousNote = $stats->partialMonth($year - 1, $index + 1);
                $months[] = [
                    'label' => $label,
                    'current' => $current[$index + 1],
                    'previous' => $previous[$index + 1],
                    'note' => $note,
                    'previousNote' => $previousNote,
                    'change' => $note === null && $previousNote === null ? MeterStats::change($current[$index + 1], $previous[$index + 1]) : null,
                ];
            }
        }

        return [
            'meter' => $meter,
            'year' => $year,
            'chips' => Meter::query()->withCount('readings')->ordered()->get()
                ->filter(fn (Meter $chip): bool => $chip->is_active || $chip->readings_count > 0 || $chip->is($meter))
                ->map(fn (Meter $chip): array => [
                    'name' => $chip->name,
                    'active' => $chip->is($meter),
                    'archived' => !$chip->is_active,
                    'url' => static::getUrl(['zaehler' => $chip->id, 'jahr' => $year]),
                ])->values()->all(),
            'previousUrl' => $meter !== null && $year > $first ? static::getUrl(['zaehler' => $meter->id, 'jahr' => $year - 1]) : null,
            'nextUrl' => $meter !== null && $year < now()->year ? static::getUrl(['zaehler' => $meter->id, 'jahr' => $year + 1]) : null,
            'months' => $months,
            'hasMonths' => collect($months)->contains(fn (array $row): bool => $row['current'] !== null || $row['previous'] !== null),
            'events' => $meter?->per_event && $stats !== null ? $stats->events($year) : [],
            'manageUrl' => MeterResource::canAccess() ? MeterResource::getUrl() : null,
        ];
    }
}
