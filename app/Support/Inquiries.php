<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Inquiry;
use App\Models\InquiryDate;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Anfragen (Optionen) wie InquiryRepository der PHP-Version. Je Tag zählt die
 * Reihenfolge des Eingangs der offenen Anfragen: die erste ist die 1. Option,
 * die nächste die 2. … Belegt ein bestätigtes, nicht abgeschlossenes Event den
 * Tag, ist er „nicht verfügbar“ – die Optionen dort zeigt der Kalender nicht.
 * Bestätigen macht aus einem Wunschtermin ein Event, Zurückziehen archiviert.
 */
final class Inquiries
{
    /** Ränge bis 6 mit Ziffern-Emoji, danach „7. Option“ */
    private const EMOJI = [1 => '1️⃣', 2 => '2️⃣', 3 => '3️⃣', 4 => '4️⃣', 5 => '5️⃣', 6 => '6️⃣'];

    /**
     * Tage im Zeitraum, die bestätigte, offene Events belegen (letzter Tag wie
     * EventDisplay::lastDay, das Ende ist exklusiv gespeichert).
     *
     * @return array<string, true> Y-m-d => true
     */
    public static function blockedDays(CarbonInterface $from, CarbonInterface $until): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $until = Carbon::parse($until)->startOfDay();
        $events = Event::query()
            ->where('status', 'bestätigt')
            ->where('closed', false)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<', $until->copy()->addDay())
            ->where(fn ($query) => $query->where('starts_at', '>=', $from->copy()->subDays(60))->orWhere('ends_at', '>=', $from))
            ->get(['id', 'starts_at', 'ends_at']);

        $blocked = [];
        foreach ($events as $event) {
            $day = $event->starts_at->copy()->startOfDay();
            $last = EventDisplay::lastDay($event);
            for (; $day->lte($last); $day->addDay()) {
                if ($day->betweenIncluded($from, $until)) {
                    $blocked[$day->toDateString()] = true;
                }
            }
        }
        ksort($blocked);

        return $blocked;
    }

    /**
     * Rang der offenen Anfragen je Tag im Zeitraum, nach Eingang.
     *
     * @return array<string, array<int, int>> Y-m-d => [Anfrage-ID => Rang]
     */
    public static function ranks(CarbonInterface $from, CarbonInterface $until): array
    {
        $rows = InquiryDate::query()
            ->join('inquiries', 'inquiries.id', '=', 'inquiry_dates.inquiry_id')
            ->where('inquiries.status', Inquiry::OPEN)
            // Ende ausschließlich: SQLite speichert das Datum mit Uhrzeit, ein BETWEEN verlöre den letzten Tag
            ->where('inquiry_dates.option_date', '>=', Carbon::parse($from)->toDateString())
            ->where('inquiry_dates.option_date', '<', Carbon::parse($until)->addDay()->toDateString())
            ->orderBy('inquiry_dates.option_date')
            ->orderBy('inquiries.created_at')
            ->orderBy('inquiries.id')
            ->get(['inquiry_dates.option_date', 'inquiry_dates.inquiry_id']);

        $ranks = [];
        foreach ($rows as $row) {
            $date = Carbon::parse($row->option_date)->toDateString();
            $ranks[$date][(int) $row->inquiry_id] = count($ranks[$date] ?? []) + 1;
        }

        return $ranks;
    }

    /**
     * Wie steht ein Wunschtermin? Nicht verfügbar, wenn ein bestätigtes Event
     * den Tag belegt; sonst der Rang, solange die Anfrage offen ist.
     *
     * @return array{available: bool, rank: ?int, label: string}
     */
    public static function slot(InquiryDate $date): array
    {
        $day = Carbon::parse($date->option_date);
        if (isset(self::blockedDays($day, $day)[$day->toDateString()])) {
            return ['available' => false, 'rank' => null, 'label' => 'Nicht verfügbar'];
        }
        $rank = self::ranks($day, $day)[$day->toDateString()][(int) $date->inquiry_id] ?? null;

        return ['available' => true, 'rank' => $rank, 'label' => $rank === null ? '–' : self::rankLabel($rank)];
    }

    /**
     * Stand aller Wunschtermine einer Anfrage mit zwei Abfragen statt zwei je Termin.
     *
     * @return array<int, array{available: bool, rank: ?int, label: string}> InquiryDate-ID => Stand
     */
    public static function slots(Inquiry $inquiry): array
    {
        $dates = $inquiry->dates;
        if ($dates->isEmpty()) {
            return [];
        }
        $from = Carbon::parse($dates->min('option_date'));
        $until = Carbon::parse($dates->max('option_date'));
        $blocked = self::blockedDays($from, $until);
        $ranks = self::ranks($from, $until);

        $slots = [];
        foreach ($dates as $date) {
            $day = $date->option_date->toDateString();
            if (isset($blocked[$day])) {
                $slots[$date->id] = ['available' => false, 'rank' => null, 'label' => 'Nicht verfügbar'];

                continue;
            }
            $rank = $ranks[$day][(int) $inquiry->id] ?? null;
            $slots[$date->id] = ['available' => true, 'rank' => $rank, 'label' => $rank === null ? '–' : self::rankLabel($rank)];
        }

        return $slots;
    }

    public static function rankLabel(int $rank): string
    {
        return isset(self::EMOJI[$rank]) ? self::EMOJI[$rank] . ' Option' : $rank . '. Option';
    }

    /** Titel im Kalender: „1️⃣ Titel (1. Opt.)“ */
    public static function barTitle(string $title, int $rank): string
    {
        return (isset(self::EMOJI[$rank]) ? self::EMOJI[$rank] . ' ' : '') . $title . ' (' . $rank . '. Opt.)';
    }

    /** Optionsfrist für neue Anfragen */
    public static function defaultDeadline(): CarbonInterface
    {
        return today()->addDays(Inquiry::DEADLINE_DAYS);
    }

    /**
     * Einen Wunschtermin bestätigen: Daraus entsteht ein bestätigtes Event mit
     * den Eckdaten der Anfrage (ganztägig, Ende exklusiv wie beim Import), die
     * Anfrage ist danach bestätigt und verweist auf das Event.
     */
    public static function confirm(Inquiry $inquiry, InquiryDate $date): Event
    {
        if (!$inquiry->isOpen()) {
            throw new InvalidArgumentException('Nur offene Anfragen können bestätigt werden.');
        }
        if ((int) $date->inquiry_id !== (int) $inquiry->id) {
            throw new InvalidArgumentException('Der Termin gehört nicht zu dieser Anfrage.');
        }
        if (!self::slot($date)['available']) {
            throw new InvalidArgumentException('Der gewählte Termin ist nicht mehr verfügbar.');
        }

        return DB::transaction(function () use ($inquiry, $date): Event {
            $day = Carbon::parse($date->option_date)->startOfDay();
            $event = Event::create([
                'title' => $inquiry->title,
                'promoter_id' => $inquiry->promoter_id,
                'starts_at' => $day,
                'ends_at' => $day->copy()->addDay(),
                'status' => 'bestätigt',
                'description' => $inquiry->comment,
                'event_type1' => $inquiry->event_type1,
                'event_type2' => $inquiry->event_type2,
                'seating' => $inquiry->seating ?? [],
                'areas' => $inquiry->areas ?? [],
            ]);
            $inquiry->update(['status' => Inquiry::CONFIRMED, 'event_id' => $event->id, 'confirmed_date_id' => $date->id]);

            return $event;
        });
    }

    public static function withdraw(Inquiry $inquiry): void
    {
        if (!$inquiry->isOpen()) {
            throw new InvalidArgumentException('Nur offene Anfragen können zurückgezogen werden.');
        }
        $inquiry->update(['status' => Inquiry::WITHDRAWN]);
    }
}
