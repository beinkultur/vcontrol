<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Zeitraum und Raster einer Kalender-Ansicht wie in der PHP-Version: Monat,
 * Woche, Tag, 4 Wochen. Wochen beginnen am Montag. Der Monat zeigt nur seine
 * Tage, die Zellen davor und danach bleiben leer; 4 Wochen beginnen am Montag
 * der Woche des gewählten Tags.
 */
final class CalendarView
{
    public const MONTH = 'month';

    public const WEEK = 'week';

    public const DAY = 'day';

    public const FOUR_WEEKS = '4weeks';

    /** Reihenfolge der Reiter wie in der PHP-Version */
    public const LABELS = [self::MONTH => 'Monat', self::WEEK => 'Woche', self::DAY => 'Tag', self::FOUR_WEEKS => '4 Wochen'];

    public const WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    private const DAYS = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    public function __construct(public readonly string $view, public readonly CarbonImmutable $date)
    {
    }

    /** Ungültige Werte (Ansicht, Datum wie 2026-13-45) fallen auf Monat und heute zurück. */
    public static function make(?string $view, ?string $date): self
    {
        $view = array_key_exists((string) $view, self::LABELS) ? (string) $view : self::MONTH;
        $parsed = is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) && (int) $parts[1] >= 2000 && (int) $parts[1] <= 2100
            ? CarbonImmutable::parse($date)
            : CarbonImmutable::today();

        return new self($view, $parsed->startOfDay());
    }

    /** Erster Tag mit Einträgen */
    public function from(): CarbonImmutable
    {
        return match ($this->view) {
            self::MONTH => $this->date->startOfMonth(),
            self::FOUR_WEEKS, self::WEEK => $this->date->startOfWeek(CarbonInterface::MONDAY),
            default => $this->date,
        };
    }

    /** Letzter Tag mit Einträgen (einschließlich) */
    public function until(): CarbonImmutable
    {
        return match ($this->view) {
            self::MONTH => $this->date->endOfMonth()->startOfDay(),
            self::FOUR_WEEKS => $this->from()->addDays(27),
            self::WEEK => $this->from()->addDays(6),
            default => $this->date,
        };
    }

    public function contains(CarbonImmutable $day): bool
    {
        return $day->betweenIncluded($this->from(), $this->until());
    }

    /** @return list<list<CarbonImmutable>> Zeilen mit je sieben Tagen (Monat: ganze Wochen), Tag: eine Zeile mit einem Tag */
    public function weeks(): array
    {
        if ($this->view === self::DAY) {
            return [[$this->date]];
        }
        $weeks = [];
        $day = $this->from()->startOfWeek(CarbonInterface::MONDAY);
        $until = $this->until()->endOfWeek(CarbonInterface::SUNDAY)->startOfDay();
        while ($day->lte($until)) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = $day;
                $day = $day->addDay();
            }
            $weeks[] = $week;
        }

        return $weeks;
    }

    public function previous(): CarbonImmutable
    {
        return match ($this->view) {
            self::MONTH => $this->date->startOfMonth()->subMonth(),
            self::FOUR_WEEKS => $this->date->subDays(28),
            self::WEEK => $this->date->subDays(7),
            default => $this->date->subDay(),
        };
    }

    public function next(): CarbonImmutable
    {
        return match ($this->view) {
            self::MONTH => $this->date->startOfMonth()->addMonth(),
            self::FOUR_WEEKS => $this->date->addDays(28),
            self::WEEK => $this->date->addDays(7),
            default => $this->date->addDay(),
        };
    }

    /** Datum beim Wechsel der Ansicht: der Monat beginnt am 1., die anderen behalten den Tag */
    public function dateFor(string $view): CarbonImmutable
    {
        return $view === self::MONTH ? $this->date->startOfMonth() : $this->date;
    }

    /** „Oktober 2026“, „Dienstag, 13. Oktober 2026“, „12. – 18. Oktober 2026“, „28. September – 4. Oktober 2026“ */
    public function title(): string
    {
        if ($this->view === self::MONTH) {
            return self::monthName($this->date) . ' ' . $this->date->year;
        }
        if ($this->view === self::DAY) {
            return self::DAYS[$this->date->dayOfWeekIso - 1] . ', ' . $this->date->day . '. ' . self::monthName($this->date) . ' ' . $this->date->year;
        }
        $from = $this->from();
        $until = $this->until();
        $end = $until->day . '. ' . self::monthName($until) . ' ' . $until->year;

        return match (true) {
            $from->year !== $until->year => $from->day . '. ' . self::monthName($from) . ' ' . $from->year . ' – ' . $end,
            $from->month !== $until->month => $from->day . '. ' . self::monthName($from) . ' – ' . $end,
            default => $from->day . '. – ' . $end,
        };
    }

    public static function monthName(CarbonInterface $day): string
    {
        return self::MONTHS[$day->month - 1];
    }
}
