<?php

namespace App\Filament\Support;

use App\Models\Event;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Support\MeterReadings;
use App\Support\MeterStats;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Zählerstände im Event (Durchführung › Betrieb): je aktivem Zähler „je
 * Event“ Stand Anfang, Stand Ende und der Verbrauch daraus. Ersetzt seit
 * 15.10.2026 die Stromzähler-Felder des Betriebs. Gespeichert wird über
 * saveRelationshipsUsing (MeterReadings::saveForEvent), also nach dem Event
 * selbst; leer löscht den Stand. Beide Stände müssen zwischen die übrigen
 * Stände des Zählers passen (MeterReadings::conflict).
 */
final class MeterFields
{
    /** Abschnitt „Zählerstände“; ohne Zähler „je Event“ ausgeblendet */
    public static function section(): Section
    {
        $meters = Meter::forEvents();

        return Section::make('Zählerstände')
            ->description('Stand am Anfang und am Ende des Events, der Verbrauch ergibt sich daraus. Welche Zähler hier stehen, legt die Venue unter Stammdaten › Zähler fest.')
            ->columns(['default' => 1, 'md' => 3])
            ->visible($meters->isNotEmpty())
            ->schema($meters->flatMap(fn (Meter $meter): array => [
                self::reading($meter, MeterReading::START),
                self::reading($meter, MeterReading::END),
                TextEntry::make(self::name($meter, 'consumption'))
                    ->label($meter->name . ' Verbrauch')
                    ->state(fn (Get $get): string => self::consumption($get, $meter)),
            ])->values()->all());
    }

    private static function name(Meter $meter, string $part): string
    {
        return 'meter_' . $meter->id . '_' . $part;
    }

    private static function reading(Meter $meter, string $position): TextInput
    {
        $start = $position === MeterReading::START;

        return TextInput::make(self::name($meter, $position))
            ->label($meter->name . ($start ? ' Stand Anfang' : ' Stand Ende'))
            ->numeric()
            ->minValue(0)
            ->maxValue(MeterReadings::MAX_VALUE)
            ->step('any')
            ->suffix($meter->unit)
            ->live(onBlur: true)
            ->dehydrated(false)
            ->afterStateHydrated(fn (TextInput $component, ?Event $record) => $component->state(MeterReadings::eventValue($record, $meter, $position)))
            ->rule(fn (Get $get, ?Event $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $meter, $position, $start): void {
                if (!is_numeric($value)) {
                    return;
                }
                $other = $get(self::name($meter, $start ? MeterReading::END : MeterReading::START));
                if (!$start && is_numeric($other) && (float) $value < (float) $other) {
                    $fail('Der Stand am Ende kann nicht kleiner sein als am Anfang.');

                    return;
                }
                // Gegen die übrigen Stände des Zählers, zu den Tagen, die gerade im Formular stehen
                $form = filled($get('starts_at'));
                $at = MeterReadings::eventTimeFor($form ? $get('starts_at') : $record?->starts_at, $form ? $get('ends_at') : $record?->ends_at, $position);
                $own = $record === null ? [] : $record->meterReadings()->where('meter_id', $meter->id)->pluck('id')->all();
                $message = MeterReadings::conflict($meter, $at, (float) $value, ignore: $own, fromEvent: true);
                if ($message !== null) {
                    $fail($message);
                }
            })
            ->saveRelationshipsUsing(fn (Event $record, mixed $state) => MeterReadings::saveForEvent($record, $meter, $position, $state));
    }

    /** Verbrauch aus den beiden Feldern, sobald beide ausgefüllt sind */
    private static function consumption(Get $get, Meter $meter): string
    {
        $start = $get(self::name($meter, MeterReading::START));
        $end = $get(self::name($meter, MeterReading::END));
        if (is_numeric($start) && is_numeric($end)) {
            return MeterStats::amount((float) $end - (float) $start, $meter->unit);
        }

        return 'aus Anfang und Ende';
    }
}
