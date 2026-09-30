<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Access\Area;
use App\Enums\AssignmentRole;
use App\Enums\RoomUsage;
use App\Enums\ServiceCode;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\EventService;
use App\Models\EventChecklist;
use App\Models\Room;
use App\Models\User;
use App\Support\StagePodests;
use Illuminate\Support\Facades\Auth;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Vorläufige Detailansicht der Event-Kerndaten, bis der Workspace steht. */
class EventInfolist
{
    /** Zeiten in der Reihenfolge des Veranstaltungstags. */
    private const TIMES = [
        'get_in' => 'Get-in',
        'load_in' => 'Load-in',
        'admission' => 'Einlass',
        'vip_admission' => 'VIP-Einlass',
        'start_time' => 'Beginn',
        'end_time' => 'Ende',
        'curfew' => 'Curfew',
        'load_out' => 'Load-out',
    ];

    /** @return list<string> */
    private static function roomNames(Event $event, RoomUsage $usage): array
    {
        return $event->rooms
            ->filter(fn (Room $room): bool => $room->pivot->usage_type === $usage->value)
            ->sortBy('sort_order')
            ->pluck('name')
            ->values()
            ->all();
    }

    /** @return list<string> Nur erfasste Punkte, z. B. „Hands: ja“. */
    private static function checklistItems(?EventChecklist $checklist): array
    {
        if ($checklist === null) {
            return [];
        }

        $items = [];
        foreach (EventForm::CHECKS as $field => $label) {
            $value = $checklist->{$field};
            $answer = $value === null ? null : (EventForm::CHECK_OPTIONS[$value] ?? null);
            if ($answer !== null) {
                $items[] = "{$label}: {$answer}";
            }
        }
        if (filled($checklist->merch_fee)) {
            $items[] = 'Merch-Fee: ' . $checklist->merch_fee;
        }
        foreach (['power_ant' => 'Miete Elektro-Ameise', 'house_rig_early' => 'Haus-Rig ab 7 Uhr', 'briefing_complete' => 'Briefing vollständig'] as $field => $label) {
            if ($checklist->{$field}) {
                $items[] = $label;
            }
        }

        return $items;
    }

    private static function canSeeFinance(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can(Area::Buchhaltung);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Buchung')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('title')->label('Titel')->columnSpanFull(),
                        TextEntry::make('promoter.name')->label('Veranstalter')->placeholder('–'),
                        TextEntry::make('va_id')->label('VA-ID')->placeholder('–'),
                        TextEntry::make('starts_at')->label('Beginn')->dateTime('D, d.m.Y H:i'),
                        TextEntry::make('ends_at')->label('Ende')->dateTime('D, d.m.Y H:i')->placeholder('–'),
                        TextEntry::make('event_type1')->label('Kategorie 1')->placeholder('–'),
                        TextEntry::make('event_type2')->label('Kategorie 2')->placeholder('–'),
                        TextEntry::make('description')->label('Beschreibung')->placeholder('–')->columnSpanFull(),
                        TextEntry::make('booking_notes')->label('Interne Buchungsnotizen')->placeholder('–')->columnSpanFull(),
                    ]),
                Section::make('Status')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')->label('VA-Status')->badge(),
                        IconEntry::make('doing_closed')->label('Durchführung abgeschlossen')->boolean(),
                        IconEntry::make('closed')->label('Event abgeschlossen')->boolean(),
                        TextEntry::make('sold_out_award')
                            ->label('Sold-Out-Award')
                            ->state(fn (Event $record): ?string => match ($record->stage?->sold_out_award) {
                                true => 'ja',
                                false => 'nein',
                                default => null,
                            })
                            ->placeholder('–'),
                    ]),
                Section::make('Zeiten')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema(array_map(
                        fn (string $field, string $label): TextEntry => TextEntry::make("schedule.{$field}")->label($label)->time('H:i')->placeholder('–'),
                        array_keys(self::TIMES),
                        self::TIMES,
                    )),
                Section::make('Finanzen')
                    ->columnSpanFull()
                    ->columns(3)
                    ->visible(fn (): bool => self::canSeeFinance())
                    ->schema([
                        TextEntry::make('finance.contract_status')->label('Vertragsstatus')->placeholder('–'),
                        TextEntry::make('finance.accounting_status')->label('FIBU-Status')->badge()->placeholder('–'),
                        TextEntry::make('finance.price_list')->label('Preisliste')->placeholder('–'),
                        TextEntry::make('finance.rent')->label('Miete')->money('EUR', locale: 'de')->placeholder('–'),
                        TextEntry::make('finance.invoice_numbers')->label('Rechnungsnummern')->badge()->color('gray')->placeholder('–'),
                        IconEntry::make('finance.accounting_closed')->label('Abrechnung abgeschlossen')->boolean(),
                    ]),
                Section::make('Rollen am Event')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('assignments')
                            ->hiddenLabel()
                            ->state(fn (Event $record): array => $record->assignments
                                ->sortBy(fn (EventAssignment $a): int => array_search($a->role, AssignmentRole::cases(), true))
                                ->map(fn (EventAssignment $a): string => $a->role->getLabel() . ': ' . $a->assigneeName()
                                    . ($a->starts_at || $a->ends_at ? ' (' . substr((string) $a->starts_at, 0, 5) . '–' . substr((string) $a->ends_at, 0, 5) . ')' : ''))
                                ->values()->all())
                            ->listWithLineBreaks()
                            ->placeholder('Niemand zugeordnet'),
                    ]),
                Section::make('Räume')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('backstages')
                            ->label('Backstage')
                            ->state(fn (Event $record): array => self::roomNames($record, RoomUsage::Backstage))
                            ->badge()
                            ->placeholder('–'),
                        TextEntry::make('offices')
                            ->label('Büros')
                            ->state(fn (Event $record): array => self::roomNames($record, RoomUsage::Office))
                            ->badge()
                            ->placeholder('–'),
                    ]),
                Section::make('Leistungen')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('services')
                            ->hiddenLabel()
                            ->state(fn (Event $record): array => $record->services
                                ->sortBy(fn (EventService $s): int => array_search($s->service, ServiceCode::cases(), true))
                                ->map(fn (EventService $s): string => collect([
                                    $s->service->getLabel(),
                                    $s->responsible?->getLabel(),
                                    $s->providerName(),
                                ])->filter()->implode(' · '))
                                ->values()->all())
                            ->listWithLineBreaks()
                            ->placeholder('Nichts festgelegt'),
                    ]),
                Section::make('Bühne')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('stage_summary')
                            ->label('Maße, Höhe, Podeste')
                            ->state(fn (Event $record): string => StagePodests::summary($record->stage)['text'])
                            ->color(fn (Event $record): ?string => StagePodests::summary($record->stage)['alert'] ? 'danger' : null),
                        TextEntry::make('stage_inventory')
                            ->label('Bestand')
                            ->state(function (Event $record): string {
                                $total = StagePodests::total($record->stage) ?? 0;
                                $inventory = StagePodests::inventory();

                                return $total > $inventory
                                    ? ($total - $inventory) . ' Podeste über dem Bestand von ' . $inventory . ' – Nachbestellung nötig'
                                    : 'im Bestand von ' . $inventory;
                            }),
                        TextEntry::make('stage.stage_notes')->label('Anmerkungen')->placeholder('–'),
                    ]),
                Section::make('Checkliste')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('checklist_items')
                            ->hiddenLabel()
                            ->state(fn (Event $record): array => self::checklistItems($record->checklist))
                            ->listWithLineBreaks()
                            ->placeholder('Nichts erfasst'),
                    ]),
                Section::make('Halle')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('areas')->label('Bereiche')->badge()->placeholder('–'),
                        TextEntry::make('seating')->label('Bestuhlung')->badge()->placeholder('–'),
                        TextEntry::make('ticketing')->label('Ticketing')->placeholder('–'),
                        TextEntry::make('pax_expected')->label('PAX erwartet')->placeholder('–'),
                        TextEntry::make('pax')->label('PAX abgerechnet')->placeholder('–'),
                        TextEntry::make('onsite_contact')->label('Ansprechpartner vor Ort')->placeholder('–'),
                        TextEntry::make('wlan')->label('WLAN')->placeholder('–'),
                    ]),
            ]);
    }
}
