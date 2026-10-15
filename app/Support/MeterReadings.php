<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Meter;
use App\Models\MeterReading;
use Illuminate\Support\Carbon;

/**
 * Zählerstände schreiben und prüfen – je Event (Stand Anfang/Ende in
 * Durchführung › Betrieb) und frei (Seite Zählerstände). Ein Stand muss
 * zwischen seine Nachbarn passen: nicht kleiner als der vorige, nicht größer
 * als der folgende, außer bei einem Zählerwechsel. So fällt ein Tippfehler
 * beim Erfassen auf, statt die Auswertung zu verfälschen (MeterStats).
 */
final class MeterReadings
{
    /** Spalte decimal(14,3) */
    public const MAX_VALUE = 99_999_999_999;

    /**
     * Wann ein Event-Stand gilt: Anfang am Morgen des ersten Event-Tags, Ende am
     * Abend des letzten – abgelesen wird beim Get-in und nach dem Load-out.
     */
    public static function eventTime(Event $event, string $position): Carbon
    {
        return self::eventTimeFor($event->starts_at, $event->ends_at, $position);
    }

    /** Wie eventTime(), aus Beginn und Ende (Formularwerte oder Carbon) */
    public static function eventTimeFor(mixed $startsAt, mixed $endsAt, string $position): Carbon
    {
        $first = filled($startsAt) ? Carbon::parse($startsAt) : now();
        if ($position === MeterReading::START) {
            return $first->copy()->setTime(0, 0);
        }
        $last = filled($endsAt) ? Carbon::parse($endsAt) : $first;

        return ($last->greaterThan($first) ? $last : $first)->copy()->setTime(23, 59, 59);
    }

    /** Stand eines Events fürs Formular, ohne Nullen am Ende („12500.5“) */
    public static function eventValue(?Event $event, Meter $meter, string $position): ?string
    {
        $reading = $event?->meterReadings->first(fn (MeterReading $reading): bool => $reading->meter_id === $meter->id && $reading->position === $position);

        return $reading === null ? null : self::plain($reading->value);
    }

    /** Aus dem Event-Formular: leer löscht den Stand, sonst anlegen oder ändern. */
    public static function saveForEvent(Event $event, Meter $meter, string $position, mixed $state): void
    {
        $existing = $event->meterReadings()->where('meter_id', $meter->id)->where('position', $position)->first();
        if (!is_numeric($state)) {
            $existing?->delete();

            return;
        }

        $values = ['value' => round((float) $state, 3), 'read_at' => self::eventTime($event, $position)];
        if ($existing === null) {
            $event->meterReadings()->create(['meter_id' => $meter->id, 'position' => $position, ...$values]);
        } else {
            $existing->update($values);
        }
    }

    /** Neue Event-Tage: die Stände ziehen mit (ohne Eintrag im Protokoll – das Event hat einen) */
    public static function syncEventTimes(Event $event): void
    {
        foreach ([MeterReading::START, MeterReading::END] as $position) {
            MeterReading::query()
                ->where('event_id', $event->getKey())
                ->where('position', $position)
                ->update(['read_at' => self::eventTime($event, $position)]);
        }
    }

    /**
     * Passt der Stand zwischen seine Nachbarn? Fehlermeldung oder null.
     *
     * @param  list<int>  $ignore  Stände, die nicht mitzählen: der bearbeitete selbst, die des Events
     */
    public static function conflict(Meter $meter, Carbon $at, float $value, bool $restart = false, array $ignore = [], bool $fromEvent = false): ?string
    {
        $others = MeterReading::query()->where('meter_id', $meter->id)->whereNotIn('id', $ignore === [] ? [0] : $ignore);
        $previous = (clone $others)->where('read_at', '<=', $at)->orderByDesc('read_at')->orderByDesc('id')->first();
        $next = (clone $others)->where('read_at', '>', $at)->orderBy('read_at')->orderBy('id')->first();

        if (!$restart && $previous !== null && $value < (float) $previous->value) {
            return 'Kleiner als der vorige Stand (' . self::describe($previous, $meter) . ').'
                . ($fromEvent ? ' Ein neuer Zähler wird zuerst unter Zählerstände als Zählerwechsel erfasst.' : ' Bei einem neuen Zähler „Zählerwechsel“ wählen.');
        }
        if ($next !== null && !$next->restart && $value > (float) $next->value) {
            return 'Größer als der folgende Stand (' . self::describe($next, $meter) . ').';
        }

        return null;
    }

    /** „485033.5“ statt „485033.500“ – für Eingabefelder */
    public static function plain(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }

    /** „485.033 kWh am 12.10.2026, Event Ende „Rock im Herbst““ */
    private static function describe(MeterReading $reading, Meter $meter): string
    {
        $text = MeterStats::reading($reading->value, $meter->unit) . ' am ' . $reading->read_at->format('d.m.Y');
        if ($reading->event_id === null) {
            return $text . ' um ' . $reading->read_at->format('H:i') . ' Uhr';
        }
        $title = Event::query()->whereKey($reading->event_id)->value('title');

        return $text . ', ' . $reading->sourceLabel() . (filled($title) ? ' „' . $title . '“' : '');
    }
}
