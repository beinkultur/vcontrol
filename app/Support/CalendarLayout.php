<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Anordnung der Kalender-Einträge, wie die PHP-Version sie zeichnet:
 * - Monat und 4 Wochen: alles als Balken in Bahnen je Woche (lanes) –
 *   Mehrtägiges über die Tage, Zeitgebundenes nur am ersten Tag; je Tag
 *   Events zuerst (CalendarItem::rank).
 * - Woche und Tag: ganztägige Balken oben, darunter ein Zeitraster mit Blöcken
 *   (timeGrid); Überlappendes steht nebeneinander. Das Raster reicht von 6 bis
 *   22 Uhr und wächst, wenn ein Eintrag früher beginnt oder später endet.
 */
final class CalendarLayout
{
    /**
     * @param  list<CarbonImmutable>  $days  die Tage der Zeile
     * @param  list<CalendarItem>  $items
     * @param  bool  $timedAsBars  zeitgebundene Einträge mitnehmen (Monat, 4 Wochen); sonst nur ganztägige
     * @return list<list<array{item: CalendarItem, column: int, span: int, fromBefore: bool, goesOn: bool}>>
     */
    public static function lanes(array $days, array $items, bool $timedAsBars = true, ?CarbonImmutable $visibleFrom = null, ?CarbonImmutable $visibleUntil = null): array
    {
        $first = $days[0];
        $last = $days[count($days) - 1];
        $from = $visibleFrom !== null && $visibleFrom->gt($first) ? $visibleFrom : $first;
        $until = $visibleUntil !== null && $visibleUntil->lt($last) ? $visibleUntil : $last;
        $segments = [];
        foreach ($items as $index => $item) {
            if (!$item->isAllDay() && !$timedAsBars) {
                continue;
            }
            $itemLast = $item->isAllDay() ? $item->lastDay : $item->firstDay;
            if ($itemLast->lt($from) || $item->firstDay->gt($until)) {
                continue;
            }
            $start = $item->firstDay->lt($from) ? $from : $item->firstDay;
            $end = $itemLast->gt($until) ? $until : $itemLast;
            $segments[] = [
                'item' => $item,
                'column' => (int) $first->diffInDays($start) + 1,
                'span' => (int) $start->diffInDays($end) + 1,
                'fromBefore' => $item->firstDay->lt($start),
                'goesOn' => $itemLast->gt($end),
                'order' => [$item->isAllDay() ? 0 : 1, $index],
            ];
        }

        // Frühere Spalte zuerst, dann Events vor dem Rest (CalendarItem::rank),
        // längere Balken zuerst, sonst ganztägig vor zeitgebunden
        usort($segments, fn (array $a, array $b): int => [$a['column'], $a['item']->rank(), -$a['span'], $a['order']]
            <=> [$b['column'], $b['item']->rank(), -$b['span'], $b['order']]);

        $lanes = [];
        $taken = [];
        foreach ($segments as $segment) {
            unset($segment['order']);
            $columns = range($segment['column'], $segment['column'] + $segment['span'] - 1);
            $lane = 0;
            while (array_intersect($columns, $taken[$lane] ?? []) !== []) {
                $lane++;
            }
            $lanes[$lane][] = $segment;
            $taken[$lane] = [...($taken[$lane] ?? []), ...$columns];
        }
        ksort($lanes);

        return array_values($lanes);
    }

    /**
     * Zeitraster für Woche und Tag: Stunden von–bis und je Tag die Blöcke mit
     * Lage in Prozent (oben, Höhe, links, Breite).
     *
     * @param  list<CarbonImmutable>  $days
     * @param  list<CalendarItem>  $items
     * @return array{from: int, until: int, days: list<list<array{item: CalendarItem, top: float, height: float, left: float, width: float}>>}
     */
    public static function timeGrid(array $days, array $items): array
    {
        $timed = array_values(array_filter($items, fn (CalendarItem $item): bool => !$item->isAllDay()));
        $fromHour = 6;
        $untilHour = 22;
        foreach ($timed as $item) {
            [$start, $end] = $item->minutes();
            $fromHour = min($fromHour, intdiv($start, 60));
            $untilHour = max($untilHour, (int) ceil($end / 60));
        }
        $span = ($untilHour - $fromHour) * 60;

        $grid = [];
        foreach ($days as $day) {
            $blocks = [];
            foreach ($timed as $item) {
                if (!$item->firstDay->equalTo($day)) {
                    continue;
                }
                [$start, $end] = $item->minutes();
                $blocks[] = ['item' => $item, 'start' => $start, 'end' => max($end, $start + 15)];
            }
            usort($blocks, fn (array $a, array $b): int => [$a['start'], $a['item']->sortKey()] <=> [$b['start'], $b['item']->sortKey()]);
            $grid[] = self::placeBlocks($blocks, $fromHour * 60, $span);
        }

        return ['from' => $fromHour, 'until' => $untilHour, 'days' => $grid];
    }

    /**
     * Überlappende Blöcke nebeneinander: je Gruppe so viele Spalten wie nötig.
     *
     * @param  list<array{item: CalendarItem, start: int, end: int}>  $blocks  nach Beginn sortiert
     * @return list<array{item: CalendarItem, top: float, height: float, left: float, width: float}>
     */
    private static function placeBlocks(array $blocks, int $offset, int $span): array
    {
        $placed = [];
        $cluster = [];
        $clusterEnd = -1;
        $flush = function () use (&$cluster, &$placed, $offset, $span): void {
            $columnsEnd = [];
            foreach ($cluster as &$block) {
                $column = 0;
                while (isset($columnsEnd[$column]) && $columnsEnd[$column] > $block['start']) {
                    $column++;
                }
                $columnsEnd[$column] = $block['end'];
                $block['column'] = $column;
            }
            unset($block);
            $count = max(1, count($columnsEnd));
            foreach ($cluster as $block) {
                $placed[] = [
                    'item' => $block['item'],
                    'top' => round(($block['start'] - $offset) / $span * 100, 3),
                    'height' => round(($block['end'] - $block['start']) / $span * 100, 3),
                    'left' => round($block['column'] / $count * 100, 3),
                    'width' => round(100 / $count, 3),
                ];
            }
            $cluster = [];
        };
        foreach ($blocks as $block) {
            if ($cluster !== [] && $block['start'] >= $clusterEnd) {
                $flush();
                $clusterEnd = -1;
            }
            $cluster[] = $block;
            $clusterEnd = max($clusterEnd, $block['end']);
        }
        if ($cluster !== []) {
            $flush();
        }

        return $placed;
    }

    /**
     * Einträge eines Tags (Liste am Handy): Events zuerst, sonst nach Uhrzeit,
     * ganztägig zuerst.
     *
     * @param  list<CalendarItem>  $items
     * @return list<CalendarItem>
     */
    public static function day(CarbonImmutable $day, array $items): array
    {
        $list = array_values(array_filter($items, fn (CalendarItem $item): bool => !$item->firstDay->gt($day)
            && !($item->isAllDay() ? $item->lastDay : $item->firstDay)->lt($day)));
        usort($list, fn (CalendarItem $a, CalendarItem $b): int => [$a->rank(), $a->sortKey()] <=> [$b->rank(), $b->sortKey()]);

        return $list;
    }
}
