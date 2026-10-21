<?php

namespace App\Filament\Resources\Accounting\Pages;

use App\Access\Module;
use App\Enums\Responsible;
use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Accounting\AccountingResource;
use App\Filament\Resources\Costings\CostingResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\Concerns\HasEventHeading;
use App\Filament\Support\AccountingFields;
use App\Models\Event;
use App\Models\EventService;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\OrderSlip;
use App\Support\AccountingChecks;
use App\Support\EventDisplay;
use App\Support\ExecutionCheck;
use App\Support\MeterReadings;
use App\Support\StagePodests;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Buchhaltungs-Dashboard eines Events (21.10.2026): links die Felder der
 * Buchhaltung zum Bearbeiten (AccountingFields – mit Buchhaltung „Bearbeiten“,
 * auch wenn die Rolle Events nur lesen darf), rechts alles, was für die
 * Abrechnung zählt, aus den übrigen Bereichen des Events: Costing, Leistungen
 * aus der Durchführung, Zählerstände, Bestellscheine, Leistungen über die Venue,
 * Schäden. Geöffnet aus der Buchhaltungsliste.
 */
class AccountingDashboard extends EditRecord
{
    use BoxedPage;
    use HasEventHeading;

    protected static string $resource = AccountingResource::class;

    /** Lesen mit Recht auf die Buchhaltung; was sich bearbeiten lässt, regelt AccountingFields */
    public static function canAccess(array $parameters = []): bool
    {
        return AccountingResource::canViewAny();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        abort_unless(AccountingFields::canEdit(), 403);

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    public function saveFormComponentOnly(\Filament\Schemas\Components\Component $component): void
    {
        abort_unless(AccountingFields::canEdit(), 403);

        parent::saveFormComponentOnly($component);
    }

    /**
     * Filament gäbe alle Spalten des Events in den Seitenzustand – auch das
     * WLAN-Passwort, das nur Event-Bearbeiter sehen. Das Dashboard braucht davon
     * nur PAX abgerechnet; Finanzen und Eingangsrechnungen laden ihre Felder selbst.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return \Illuminate\Support\Arr::only($data, ['pax']);
    }

    /**
     * Gespeichert wird nur PAX abgerechnet am Event selbst.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return \Illuminate\Support\Arr::only($data, ['pax']);
    }

    /** Nach dem Speichern auf der Seite bleiben */
    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    protected function getFormActions(): array
    {
        return AccountingFields::canEdit() ? parent::getFormActions() : [];
    }

    public function getBreadcrumbs(): array
    {
        return [AccountingResource::getUrl() => 'Buchhaltung', 'Dashboard'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('event')
                ->label('Event öffnen')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (): bool => EventResource::canView($this->getRecord()))
                ->url(fn (): string => EventResource::getUrl(EventResource::canEdit($this->getRecord()) ? 'edit' : 'view', ['record' => $this->getRecord()])),
            $this->historyAction(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'xl' => 5])->schema([
                    Group::make(AccountingFields::all())->columnSpan(['xl' => 3]),
                    Group::make([
                        self::eventSection(),
                        self::executionSection(),
                        self::meterSection(),
                        self::orderSlipSection(),
                        self::servicesSection(),
                        self::damagesSection(),
                    ])->columnSpan(['xl' => 2]),
                ]),
            ]);
    }

    private static function eventSection(): Section
    {
        $costing = fn (Event $record) => CostingResource::canAccess() ? $record->costings()->latest('id')->first() : null;

        return Section::make('Event')
            ->compact()
            ->schema([
                self::entry('overview_date', 'Datum', fn (Event $record): string => $record->starts_at === null ? '–'
                    : EventDisplay::weekday($record->starts_at) . ', ' . $record->starts_at->format('d.m.Y')
                        . (EventDisplay::isMultiDay($record) ? ' bis ' . $record->ends_at->format('d.m.Y') : '')),
                self::entry('overview_promoter', 'Veranstalter', fn (Event $record): string => $record->promoter?->name ?? '–'),
                self::entry('overview_category', 'VA-Kategorie', fn (Event $record): string => collect([$record->event_type1, $record->event_type2])->filter()->implode(' · ') ?: '–'),
                self::entry('overview_seating', 'Bestuhlung', fn (Event $record): string => implode(', ', (array) $record->seating) ?: '–'),
                self::entry('overview_price_list', 'Preisliste', fn (Event $record): string => $record->priceList?->name ?? '–')
                    ->visible(fn (): bool => Module::PriceLists->isEnabled()),
                self::entry('overview_costing', 'Costing', fn (Event $record): string => ($c = $costing($record)) === null
                    ? '–'
                    : $c->label() . ' · ' . $c->statusLabel() . ' · netto ' . \App\Support\Costings::money($c->net()))
                    ->url(fn (Event $record): ?string => ($c = $costing($record)) !== null ? CostingResource::getUrl('view', ['record' => $c]) : null)
                    ->visible(fn (): bool => CostingResource::canAccess()),
                self::entry('overview_closed', 'Event abgeschlossen', fn (Event $record): string => $record->closed ? 'ja' : 'nein'),
                self::entry('overview_paid', 'Rechnungen', fn (Event $record): string => match (true) {
                    AccountingChecks::invoices($record) === [] => 'noch keine verschickt',
                    AccountingChecks::allPaid($record) => 'alle verschickten bezahlt',
                    default => 'offen: ' . implode(', ', array_map('ucfirst', array_keys(array_filter(AccountingChecks::invoices($record), fn (array $i): bool => !$i['paid'])))),
                })->color(fn (Event $record): string => AccountingChecks::allPaid($record) ? 'success' : 'gray'),
            ]);
    }

    /** Durchführung › Betrieb: was zusätzlich berechnet wird oder Rechnungen auslöst */
    private static function executionSection(): Section
    {
        $yesNo = fn (mixed $value): string => match (true) {
            $value === null || $value === '' => '–',
            $value === 'na' => 'entfällt',
            $value === true || $value === 1 || $value === '1' || $value === 'yes' => 'ja',
            default => 'nein',
        };
        $entries = [
            self::entry('execution_missing', 'Noch offen', fn (Event $record): string => implode(', ', ExecutionCheck::missing($record)) ?: 'nichts – vollständig erfasst')
                ->color(fn (Event $record): string => ExecutionCheck::isComplete($record) ? 'success' : 'warning'),
            self::entry('execution_bus_power', 'Bus-Strom', fn (Event $record): string => match ($record->operation?->bus_power) {
                null => '–',
                0 => 'keine Anschlüsse',
                1 => '1 Anschluss',
                default => $record->operation->bus_power . ' Anschlüsse',
            }),
            self::entry('execution_podests', 'Zus. Podeste', fn (Event $record): string => (string) StagePodests::billableExtra($record->stage?->podest_total))
                ->visible(fn (): bool => Module::StagePlan->isEnabled()),
        ];
        foreach (ExecutionCheck::CHECKS as $column => [$relation, $label]) {
            $entries[] = self::entry("execution_{$column}", $label, fn (Event $record): string => $column === 'merch_fee'
                ? (filled($record->checklist?->merch_fee) ? (string) $record->checklist->merch_fee : '–')
                : $yesNo($record->{$relation}?->{$column}));
        }

        return Section::make('Durchführung')
            ->description('Was zusätzlich berechnet wird oder eine Eingangsrechnung auslöst.')
            ->compact()
            ->headerActions([self::workspaceLink('execution', 'durchfuehrung', 'betrieb')])
            ->schema($entries);
    }

    private static function meterSection(): Section
    {
        $meters = Meter::forEvents();
        $number = fn (float $value): string => rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');

        return Section::make('Zählerstände')
            ->compact()
            ->visible($meters->isNotEmpty())
            ->schema($meters->map(fn (Meter $meter): TextEntry => self::entry("meter_{$meter->id}", $meter->name, function (Event $record) use ($meter, $number): string {
                $start = MeterReadings::eventValue($record, $meter, MeterReading::START);
                $end = MeterReadings::eventValue($record, $meter, MeterReading::END);
                if ($start === null && $end === null) {
                    return '–';
                }
                $unit = filled($meter->unit) ? ' ' . $meter->unit : '';

                return ($start === null ? '?' : $number((float) $start)) . ' → ' . ($end === null ? '?' : $number((float) $end))
                    . ($start !== null && $end !== null ? ' = ' . $number((float) $end - (float) $start) . $unit : '');
            }))->values()->all());
    }

    private static function orderSlipSection(): Section
    {
        return Section::make('Bestellscheine')
            ->compact()
            ->headerActions([self::workspaceLink('order_slips', 'durchfuehrung', 'bestellscheine')])
            ->schema([
                self::entry('order_slips_summary', 'Summe', function (Event $record): string {
                    $slips = $record->orderSlips()->with('items')->get();
                    if ($slips->isEmpty()) {
                        return 'keine';
                    }
                    $settled = $slips->where('is_settled', true)->count();

                    return $slips->count() . ' · ' . OrderSlip::money($slips->sum(fn (OrderSlip $slip): float => $slip->total()))
                        . ' · ' . $settled . ' abgerechnet';
                }),
            ]);
    }

    /** Gewerke, die die Venue stellt („Venue“ statt „Veranstalter“) – die berechnet die Venue weiter */
    private static function servicesSection(): Section
    {
        return Section::make('Leistungen über die Venue')
            ->compact()
            ->headerActions([self::workspaceLink('services', 'planung', 'gewerke')])
            ->schema([
                self::entry('services_venue', 'Gewerke', fn (Event $record): string => $record->services
                    ->filter(fn (EventService $s): bool => $s->responsible === Responsible::Arena && !$s->service->isRetired())
                    ->map(fn (EventService $s): string => $s->service->getLabel() . (filled($s->providerName()) ? ' (' . $s->providerName() . ')' : ''))
                    ->implode(', ') ?: 'keine'),
            ]);
    }

    private static function damagesSection(): Section
    {
        return Section::make('Schäden')
            ->compact()
            ->headerActions([self::workspaceLink('damages', 'durchfuehrung', 'schaeden')])
            ->schema([
                self::entry('damages_summary', 'Schäden', function (Event $record): string {
                    $total = $record->damages()->count();
                    $open = $record->damages()->where('is_fixed', false)->count();

                    return $total === 0 ? 'keine' : $total . ' · davon ' . $open . ' offen';
                }),
            ]);
    }

    /** Eine Zeile „Beschriftung: Wert“, nur lesend */
    private static function entry(string $name, string $label, \Closure $state): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->inlineLabel()
            ->state($state);
    }

    /** Zum Bereich im Event-Workspace, für wer das Event sehen darf */
    private static function workspaceLink(string $name, string $phase, string $section): Action
    {
        return Action::make("open_{$name}")
            ->label('Im Event')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->link()
            ->size('sm')
            ->visible(fn (?Event $record): bool => $record !== null && EventResource::canView($record))
            ->url(fn (?Event $record): ?string => $record === null ? null
                : EventResource::getUrl(EventResource::canEdit($record) ? 'edit' : 'view', ['record' => $record]) . "?phase={$phase}&bereich={$section}");
    }
}
