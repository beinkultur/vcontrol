<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Meter;
use App\Models\MeterReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Auswertung eines Zählers (Seite Zählerstände). Der Verbrauch zwischen zwei
 * aufeinanderfolgenden Ständen verteilt sich gleichmäßig auf die Zeit
 * dazwischen – daraus Monate und Jahre. Ein Zählerwechsel oder ein Rücksprung
 * (Stand kleiner als der vorige) unterbricht: dafür zählt nichts. Reicht kein
 * Stand vor und nach einen Zeitraum, bleibt er leer (null), nicht 0; ein Monat
 * braucht dafür mindestens einen Tag mit Ständen davor und danach.
 * Je Event: Stand Ende minus Anfang, dazu PAX abgerechnet und Verbrauch je PAX.
 */
final class MeterStats
{
    public const MONTHS = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

    /** @var list<array{from: int, to: int, amount: float}> Abschnitte zwischen zwei Ständen (Unix-Zeit) */
    private array $segments = [];

    /** @var array<int, float|null> Stand => Verbrauch seit dem vorigen; null beim Zählerwechsel, negativ beim Rücksprung */
    private array $deltas = [];

    /** @param  Collection<int, MeterReading>  $readings  aufsteigend nach Zeit */
    private function __construct(public readonly Meter $meter, public readonly Collection $readings)
    {
        $previous = null;
        foreach ($readings as $reading) {
            if ($previous !== null) {
                $delta = $reading->restart ? null : (float) $reading->value - (float) $previous->value;
                $this->deltas[$reading->id] = $delta;
                if ($delta !== null && $delta >= 0) {
                    $this->segments[] = ['from' => $previous->read_at->getTimestamp(), 'to' => $reading->read_at->getTimestamp(), 'amount' => $delta];
                }
            }
            $previous = $reading;
        }
    }

    public static function for(Meter $meter): self
    {
        return new self($meter, $meter->readings()
            ->with('event:id,title,starts_at,ends_at,pax')
            ->orderBy('read_at')
            // Gleiche Zeit: Event-Anfang zuerst, Event-Ende zuletzt
            ->orderByRaw("case position when 'start' then 0 when 'end' then 2 else 1 end")
            ->orderBy('id')
            ->get());
    }

    /** Verbrauch seit dem vorigen Stand; null beim ersten Stand und beim Zählerwechsel */
    public function delta(MeterReading $reading): ?float
    {
        return $this->deltas[$reading->id] ?? null;
    }

    public function latest(): ?MeterReading
    {
        return $this->readings->last();
    }

    /** @return list<int> Jahre mit Ständen, aufsteigend */
    public function years(): array
    {
        return $this->readings->map(fn (MeterReading $reading): int => $reading->read_at->year)->unique()->sort()->values()->all();
    }

    /** Verbrauch im Zeitraum [$from, $until) – null, wenn kein Abschnitt hineinreicht */
    public function between(Carbon $from, Carbon $until): ?float
    {
        $start = $from->getTimestamp();
        $end = $until->getTimestamp();
        $sum = null;
        foreach ($this->segments as $segment) {
            if ($segment['to'] === $segment['from']) {
                // Zwei Stände zur selben Zeit: alles in diesen Zeitpunkt
                if ($segment['to'] >= $start && $segment['to'] < $end) {
                    $sum = ($sum ?? 0.0) + $segment['amount'];
                }

                continue;
            }
            $overlap = min($end, $segment['to']) - max($start, $segment['from']);
            if ($overlap > 0) {
                $sum = ($sum ?? 0.0) + $segment['amount'] * $overlap / ($segment['to'] - $segment['from']);
            }
        }

        return $sum;
    }

    /** @return array<int, float|null> Monat (1–12) => Verbrauch; null mit weniger als einem Tag Ständen */
    public function months(int $year): array
    {
        $months = [];
        foreach (range(1, 12) as $month) {
            $start = Carbon::create($year, $month, 1);
            $end = $start->copy()->addMonth();
            $months[$month] = $this->covered($start, $end) < 86400 ? null : $this->between($start, $end);
        }

        return $months;
    }

    /**
     * Decken die Stände den Monat nur zum Teil ab (mehr als ein Tag fehlt)?
     * Dann „ab 09.07.“, „bis 13.10.“ oder „09.07.–13.10.“, sonst null.
     */
    public function partialMonth(int $year, int $month): ?string
    {
        $first = $this->readings->first()?->read_at;
        $last = $this->readings->last()?->read_at;
        $start = Carbon::create($year, $month, 1);
        $end = $start->copy()->addMonth();
        if ($first === null || $last === null || $this->covered($start, $end) < 86400) {
            return null;
        }
        $from = $first->greaterThan($start->copy()->addDay()) ? $first : null;
        $until = $last->lessThan($end->copy()->subDay()) ? $last : null;

        return match (true) {
            $from !== null && $until !== null => $from->format('d.m.') . '–' . $until->format('d.m.'),
            $from !== null => 'ab ' . $from->format('d.m.'),
            $until !== null => 'bis ' . $until->format('d.m.'),
            default => null,
        };
    }

    /** Sekunden des Zeitraums, für die es einen Verbrauch gibt */
    private function covered(Carbon $from, Carbon $until): int
    {
        $covered = 0;
        foreach ($this->segments as $segment) {
            $covered += max(0, min($until->getTimestamp(), $segment['to']) - max($from->getTimestamp(), $segment['from']));
        }

        return $covered;
    }

    /**
     * Das Jahr, soweit Stände reichen, und dieselben Tage im Vorjahr – so
     * vergleicht ein laufendes Jahr fair.
     *
     * @return array{amount: ?float, from: ?Carbon, until: ?Carbon, previous: ?float, perDay: ?float}
     */
    public function year(int $year): array
    {
        $empty = ['amount' => null, 'from' => null, 'until' => null, 'previous' => null, 'perDay' => null];
        $first = $this->readings->first()?->read_at;
        $last = $this->readings->last()?->read_at;
        $start = Carbon::create($year, 1, 1);
        $end = $start->copy()->addYear();
        if ($first === null || $last === null || $first->greaterThanOrEqualTo($end) || $last->lessThanOrEqualTo($start)) {
            return $empty;
        }

        $from = $first->greaterThan($start) ? $first->copy() : $start;
        $until = $last->lessThan($end) ? $last->copy() : $end;
        $amount = $this->between($from, $until);
        $days = ($until->getTimestamp() - $from->getTimestamp()) / 86400;

        return [
            'amount' => $amount,
            'from' => $from,
            'until' => $until,
            'previous' => $this->between($from->copy()->subYear(), $until->copy()->subYear()),
            'perDay' => $amount !== null && $days >= 1 ? $amount / $days : null,
        ];
    }

    /**
     * Events des Jahres (nach Beginn) mit Stand Anfang und Ende.
     *
     * @return list<array{event: Event, start: float, end: float, amount: float, pax: ?int, perPax: ?float}>
     */
    public function events(int $year): array
    {
        $rows = [];
        foreach ($this->readings->whereNotNull('event_id')->groupBy('event_id') as $readings) {
            $start = $readings->firstWhere('position', MeterReading::START);
            $end = $readings->firstWhere('position', MeterReading::END);
            $event = $start?->event ?? $end?->event;
            if ($start === null || $end === null || $event === null || $event->starts_at?->year !== $year) {
                continue;
            }
            $amount = (float) $end->value - (float) $start->value;
            $pax = $event->pax !== null && $event->pax > 0 ? (int) $event->pax : null;
            $rows[] = [
                'event' => $event,
                'start' => (float) $start->value,
                'end' => (float) $end->value,
                'amount' => $amount,
                'pax' => $pax,
                'perPax' => $pax !== null ? $amount / $pax : null,
            ];
        }
        usort($rows, fn (array $a, array $b): int => $a['event']->starts_at <=> $b['event']->starts_at);

        return $rows;
    }

    /** Stand mit bis zu drei Nachkommastellen, ohne Nullen am Ende: „485.033 kWh“, „1.234,5 m³“ */
    public static function reading(mixed $value, string $unit): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',') . ' ' . $unit;
    }

    /** Verbrauch, gerundet nach Größe: „2.683 kWh“, „48,5 m³“, „1,25 MWh“ */
    public static function amount(?float $value, string $unit): string
    {
        if ($value === null) {
            return '–';
        }
        $size = abs($value);
        $decimals = match (true) {
            $size >= 100 => 0,
            $size >= 10 => 1,
            $size >= 1 => 2,
            default => 3,
        };
        $text = number_format($value, $decimals, ',', '.');
        if ($decimals > 0) {
            $text = rtrim(rtrim($text, '0'), ',');
        }

        return ($text === '-0' ? '0' : $text) . ' ' . $unit;
    }

    /** Für Diagramme: gerundet wie amount(), als Zahl */
    public static function rounded(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $size = abs($value);

        return round($value, $size >= 100 ? 0 : ($size >= 10 ? 1 : ($size >= 1 ? 2 : 3)));
    }

    /** Veränderung in Prozent: „+12 %“, „−5 %“; null ohne Vergleichswert */
    public static function change(?float $now, ?float $before): ?string
    {
        if ($now === null || $before === null || $before <= 0) {
            return null;
        }
        $percent = (int) round(($now - $before) / $before * 100);

        return ($percent > 0 ? '+' : ($percent < 0 ? '−' : '±')) . abs($percent) . ' %';
    }
}
