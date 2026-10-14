<?php

namespace App\Filament\Pages;

use App\Access\Area;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Models\Calendar as CalendarLayer;
use App\Models\CalendarEntry;
use App\Models\User;
use App\Policies\CalendarEntryPolicy;
use App\Support\CalendarItems;
use App\Support\CalendarLayout;
use App\Support\CalendarView;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

/**
 * Kalender wie /kalender der PHP-Version: Monat, Woche, Tag, 4 Wochen (ohne
 * Angabe die Standard-Ansicht aus dem Profil); Ebenen (Kalender-Ebenen der
 * Verwaltung) mit eigener Farbe zum Ein- und Ausblenden, die Auswahl steht in
 * der Adresse. Inhalt: App\Support\CalendarItems,
 * Anordnung: App\Support\CalendarLayout. Eigene Einträge legt an, wer eine
 * Ebene bearbeiten darf (CalendarEntryPolicy). Externe: ExternCalendar.
 */
class Calendar extends Page
{
    protected static ?string $slug = 'kalender';

    protected static ?string $title = 'Kalender';

    protected static ?string $navigationLabel = 'Kalender';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    /** Eigener Punkt in der Kopfleiste nach Events und Anfragen (Vorgabe vom 14.10.2026) */
    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.calendar';

    /** Monat und 4 Wochen: so viele Bahnen je Woche, der Rest als „+n weitere“ (führt zum Tag) */
    public const MAX_LANES = 4;

    #[Url(as: 'view')]
    public ?string $ansicht = null;

    #[Url(as: 'date')]
    public ?string $datum = null;

    /** @var list<string> */
    #[Url(as: 'cal')]
    public array $ebenen = [];

    /** Direkt ein Eintrag öffnen (?entry=ID) oder ein neuer (?new_entry=1), wie in der PHP-Version */
    #[Url(as: 'entry')]
    public ?string $eintrag = null;

    #[Url(as: 'new_entry')]
    public ?string $neu = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can(Area::Kalender);
    }

    public function mount(): void
    {
        $user = self::user();
        if (filled($this->eintrag) && ($entry = CalendarEntry::query()->find((int) $this->eintrag)) !== null && $user->can('view', $entry) && !static::isExtern()) {
            $this->defaultAction = 'entry';
            $this->defaultActionArguments = ['id' => $entry->id];
        } elseif (filled($this->neu) && !static::isExtern() && $user->can('create', CalendarEntry::class)) {
            $this->defaultAction = 'createEntry';
        }
    }

    protected static function isExtern(): bool
    {
        return false;
    }

    public function getHeading(): string
    {
        return static::$title ?? 'Kalender';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = self::user();
        $cal = $this->calendarView();
        $readable = CalendarItems::readableLayers($user);
        $visible = CalendarItems::visibleLayers($user, $this->ebenen);
        $items = CalendarItems::for($user, $cal->from(), $cal->until(), $visible, static::isExtern());
        $grid = in_array($cal->view, [CalendarView::WEEK, CalendarView::DAY], true);
        $chosen = count($visible) < $readable->count() ? $visible : [];

        $rows = [];
        foreach ($cal->weeks() as $days) {
            $lanes = CalendarLayout::lanes($days, $items, !$grid, $cal->from(), $cal->until());
            $rows[] = [
                'days' => $days,
                'lanes' => $grid ? $lanes : array_slice($lanes, 0, self::MAX_LANES),
                'more' => $grid ? [] : $this->overflow($days, array_slice($lanes, self::MAX_LANES), $chosen),
                'time' => $grid ? CalendarLayout::timeGrid($days, $items) : null,
            ];
        }

        $agenda = [];
        for ($day = $cal->from(); $day->lte($cal->until()); $day = $day->addDay()) {
            $list = CalendarLayout::day($day, $items);
            if ($list !== []) {
                $agenda[] = ['day' => $day, 'items' => $list];
            }
        }

        return [
            'cal' => $cal,
            'rows' => $rows,
            'agenda' => $agenda,
            'extern' => static::isExtern(),
            'today' => CarbonImmutable::today(),
            'layers' => $readable->map(fn (CalendarLayer $layer): array => [
                'key' => $layer->key,
                'name' => $layer->name,
                'color' => $layer->color,
                'active' => in_array($layer->key, $visible, true),
                'url' => $this->url(['cal' => $this->toggled($visible, $layer->key, $readable->count())]),
            ])->all(),
            'colors' => $readable->mapWithKeys(fn (CalendarLayer $layer): array => [$layer->key => $layer->color])->all(),
            'showLayers' => $readable->count() > 1,
            'urls' => [
                'previous' => $this->url(['date' => $cal->previous()->toDateString(), 'cal' => $chosen]),
                'next' => $this->url(['date' => $cal->next()->toDateString(), 'cal' => $chosen]),
                'today' => $this->url(['date' => CarbonImmutable::today()->toDateString(), 'cal' => $chosen]),
                'views' => collect(CalendarView::LABELS)->map(fn (string $label, string $view): string => $this->url(['view' => $view, 'date' => $cal->dateFor($view)->toDateString(), 'cal' => $chosen]))->all(),
            ],
        ];
    }

    /**
     * Was über MAX_LANES hinausgeht, je Tag gezählt, mit Link zur Tagesansicht.
     *
     * @param  list<CarbonImmutable>  $days
     * @param  list<list<array{column: int, span: int}>>  $hiddenLanes
     * @param  list<string>  $chosen
     * @return list<array{column: int, count: int, url: string}>
     */
    private function overflow(array $days, array $hiddenLanes, array $chosen): array
    {
        $counts = [];
        foreach ($hiddenLanes as $lane) {
            foreach ($lane as $segment) {
                for ($column = $segment['column']; $column < $segment['column'] + $segment['span']; $column++) {
                    $counts[$column] = ($counts[$column] ?? 0) + 1;
                }
            }
        }
        ksort($counts);

        $more = [];
        foreach ($counts as $column => $count) {
            $more[] = [
                'column' => $column,
                'count' => $count,
                'url' => $this->url(['view' => CalendarView::DAY, 'date' => $days[$column - 1]->toDateString(), 'cal' => $chosen]),
            ];
        }

        return $more;
    }

    /** Ansicht aus der Adresse, sonst die Standard-Ansicht aus dem Profil (users.calendar_view), sonst Monat */
    private function calendarView(): CalendarView
    {
        return CalendarView::make($this->ansicht ?: self::user()->calendar_view, $this->datum);
    }

    /** Adresse mit Ansicht und Datum der aktuellen Seite, Änderungen aus $changes */
    private function url(array $changes): string
    {
        $cal = $this->calendarView();
        $query = array_filter([
            'view' => $changes['view'] ?? $cal->view,
            'date' => $changes['date'] ?? $cal->date->toDateString(),
            'cal' => $changes['cal'] ?? [],
        ], fn (mixed $value): bool => $value !== [] && $value !== null);

        return static::getUrl($query);
    }

    /**
     * Ebene an- oder abschalten; die letzte abgeschaltet heißt wieder alle (leer).
     *
     * @param  list<string>  $visible
     * @return list<string>
     */
    private function toggled(array $visible, string $key, int $readableCount): array
    {
        $next = in_array($key, $visible, true) ? array_values(array_diff($visible, [$key])) : [...$visible, $key];

        return count($next) === 0 || count($next) >= $readableCount ? [] : $next;
    }

    protected function getHeaderActions(): array
    {
        if (static::isExtern()) {
            return [];
        }

        return [
            Action::make('newInquiry')
                ->label('Anfrage')
                ->icon(Heroicon::OutlinedPlus)
                ->color('gray')
                ->url(fn (): string => InquiryResource::getUrl('create'))
                ->visible(fn (): bool => InquiryResource::canCreate()),
            $this->createEntryAction(),
        ];
    }

    public function createEntryAction(): Action
    {
        return Action::make('createEntry')
            ->label('Eintrag')
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => self::user()->can('create', CalendarEntry::class))
            ->modalHeading('Neuer Kalender-Eintrag')
            ->modalSubmitActionLabel('Speichern')
            ->fillForm(fn (): array => [
                'calendar_key' => array_key_first($this->editableLayers()),
                'all_day' => true,
                'start_date' => $this->calendarView()->date->toDateString(),
            ])
            ->schema(fn (): array => $this->entrySchema())
            ->action(function (array $data, Action $action): void {
                CalendarEntry::create($this->entryValues($data, $action));
                Notification::make()->success()->title('Eintrag gespeichert.')->send();
            });
    }

    /** Eigenen Eintrag öffnen: bearbeiten (und löschen), wer die Ebene bearbeiten darf, sonst ansehen */
    public function entryAction(): Action
    {
        return Action::make('entry')
            ->authorize(fn (array $arguments): bool => ($entry = self::entry($arguments)) !== null && self::user()->can('view', $entry))
            ->modalHeading(fn (array $arguments): string => self::canEdit($arguments) ? 'Eintrag bearbeiten' : (string) self::entry($arguments)?->title)
            ->modalSubmitActionLabel('Speichern')
            ->modalSubmitAction(fn (array $arguments): ?bool => self::canEdit($arguments) ? null : false)
            ->modalCancelActionLabel(fn (array $arguments): string => self::canEdit($arguments) ? 'Abbrechen' : 'Schließen')
            ->modalContent(fn (array $arguments): ?HtmlString => self::canEdit($arguments) ? null : self::entryDetails(self::entry($arguments)))
            ->fillForm(function (array $arguments): array {
                $entry = self::entry($arguments);

                return $entry === null ? [] : [
                    'calendar_key' => $entry->calendar_key,
                    'title' => $entry->title,
                    'all_day' => $entry->all_day,
                    'start_date' => $entry->starts_at->toDateString(),
                    'start_time' => $entry->all_day ? null : $entry->starts_at->format('H:i'),
                    'end_date' => $entry->ends_at?->toDateString(),
                    'end_time' => $entry->all_day || $entry->ends_at === null ? null : $entry->ends_at->format('H:i'),
                    'description' => $entry->description,
                ];
            })
            ->schema(fn (array $arguments): array => self::canEdit($arguments) ? $this->entrySchema(self::entry($arguments)) : [])
            ->extraModalFooterActions(fn (array $arguments): array => self::canEdit($arguments) ? [
                Action::make('deleteEntry')
                    ->label('Löschen')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Eintrag löschen?')
                    ->modalDescription('Der Eintrag verschwindet endgültig aus dem Kalender.')
                    ->modalSubmitActionLabel('Löschen')
                    ->cancelParentActions()
                    ->action(function () use ($arguments): void {
                        $entry = self::entry($arguments);
                        if ($entry !== null && self::user()->can('delete', $entry)) {
                            $entry->delete();
                            Notification::make()->success()->title('Eintrag gelöscht.')->send();
                        }
                    }),
            ] : [])
            ->action(function (array $data, array $arguments, Action $action): void {
                $entry = self::entry($arguments);
                if ($entry === null || !self::user()->can('update', $entry)) {
                    return;
                }
                $entry->update($this->entryValues($data, $action));
                Notification::make()->success()->title('Eintrag gespeichert.')->send();
            });
    }

    /** @return array<int, \Filament\Schemas\Components\Component|\Filament\Forms\Components\Field> */
    private function entrySchema(?CalendarEntry $entry = null): array
    {
        $layers = $this->editableLayers();

        return [
            Select::make('calendar_key')
                ->label('Ebene')
                ->options($layers)
                ->required()
                ->in(array_keys($layers))
                ->native(false),
            TextInput::make('title')
                ->label('Titel')
                ->required()
                ->maxLength(255),
            Toggle::make('all_day')
                ->label('Ganztägig')
                ->default(true)
                ->live(),
            Grid::make(2)->schema([
                DatePicker::make('start_date')
                    ->label('Beginn')
                    ->required(),
                TimePicker::make('start_time')
                    ->label('Uhrzeit')
                    ->seconds(false)
                    ->required(fn (Get $get): bool => !$get('all_day'))
                    ->visible(fn (Get $get): bool => !$get('all_day')),
                DatePicker::make('end_date')
                    ->label('Ende')
                    ->helperText('Leer: am selben Tag.')
                    ->afterOrEqual('start_date')
                    ->validationMessages(['after_or_equal' => 'Das Ende liegt vor dem Beginn.']),
                TimePicker::make('end_time')
                    ->label('Uhrzeit')
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => !$get('all_day')),
            ]),
            Textarea::make('description')
                ->label('Beschreibung')
                ->rows(3),
        ];
    }

    /**
     * Formular → Eintrag. Ganztägig: Ende einschließlich (23:59:59) wie in der
     * PHP-Version; mit Uhrzeit: Ende am Endtag (sonst am Starttag), nie vor dem Beginn.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function entryValues(array $data, Action $action): array
    {
        $allDay = (bool) ($data['all_day'] ?? true);
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $endDate = filled($data['end_date'] ?? null) ? Carbon::parse($data['end_date'])->startOfDay() : null;
        if (!$allDay) {
            $start = $start->setTimeFromTimeString((string) $data['start_time']);
        }
        $end = match (true) {
            $allDay => $endDate?->copy()->endOfDay()->startOfSecond(),
            filled($data['end_time'] ?? null) => ($endDate ?? $start->copy()->startOfDay())->copy()->setTimeFromTimeString((string) $data['end_time']),
            default => null,
        };
        if ($end !== null && $end->lt($start)) {
            Notification::make()->danger()->title('Das Ende liegt vor dem Beginn.')->send();
            $action->halt();
        }

        return [
            'calendar_key' => (string) $data['calendar_key'],
            'title' => trim((string) preg_replace('/\s+/u', ' ', (string) $data['title'])),
            'description' => filled(trim((string) ($data['description'] ?? ''))) ? trim((string) $data['description']) : null,
            'all_day' => $allDay,
            'starts_at' => $start,
            'ends_at' => $end,
        ];
    }

    /** @return array<string, string> Ebenen mit eigenen Einträgen, die das Konto bearbeiten darf */
    private function editableLayers(): array
    {
        $keys = CalendarEntryPolicy::editableKeys(self::user());

        return CalendarLayer::query()->whereIn('key', $keys)->ordered()->pluck('name', 'key')->all();
    }

    private static function entry(array $arguments): ?CalendarEntry
    {
        return CalendarEntry::query()->find((int) ($arguments['id'] ?? 0));
    }

    private static function canEdit(array $arguments): bool
    {
        $entry = self::entry($arguments);

        return $entry !== null && !static::isExtern() && self::user()->can('update', $entry);
    }

    private static function entryDetails(?CalendarEntry $entry): ?HtmlString
    {
        if ($entry === null) {
            return null;
        }

        return new HtmlString(view('filament.calendar.entry-details', ['entry' => $entry])->render());
    }

    private static function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
