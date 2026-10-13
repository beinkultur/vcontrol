<?php

namespace App\Support;

use App\Access\Level;
use App\Enums\AssignmentRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\ExternEvents\ExternEventResource;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Models\Calendar;
use App\Models\CalendarEntry;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Inquiry;
use App\Models\InquiryDate;
use App\Models\Trade;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Was der Kalender für einen Zeitraum zeigt, je Ebene und nach den Rechten
 * des Kontos (Kalender-Rechte je Ebene, App\Access\Access::calendarLevel) – wie
 * CalendarPage::loadItems und CalendarFeed der PHP-Version:
 * - „events“: die Events, fragliche blass, stornierte durchgestrichen;
 * - „anfragen“: offene Anfragen je Wunschtermin mit Rang (1️⃣ Option …), Tage,
 *   die ein bestätigtes Event belegt, fehlen;
 * - „personal“: die eigenen Dienste (House Rep., Auf-/Abschließen, VfV) – als
 *   Konto, über den verknüpften Mitarbeiter (Personen-Konten) oder das Gewerk;
 * - „veranstaltungsleitung“ und „technik“: dieselben Dienste aller Events
 *   (House Rep. bzw. VfV), dazu eigene Einträge. Ein eigener Dienst erscheint
 *   nur einmal, mit beiden Ebenen (CalendarItem::$layers, im Raster gestreift);
 * - alle anderen Ebenen: eigene Einträge (CalendarEntry).
 * Extern: dieselben lesbaren Ebenen, Links aber nur zu Events, an denen das
 * Konto beteiligt ist (Involvement), ohne „🔗“.
 */
final class CalendarItems
{
    /** Dienste, die die Ebenen spiegeln; „personal“ zeigt beide Gruppen, aber nur die eigenen */
    public const MIRRORS = [
        'veranstaltungsleitung' => [AssignmentRole::Unlock, AssignmentRole::HouseRepEarly, AssignmentRole::HouseRepLate, AssignmentRole::Lock],
        'technik' => [AssignmentRole::SafetyEarly, AssignmentRole::SafetyLate],
    ];

    private const LINK = '🔗 ';

    /**
     * @param  list<string>|null  $layers  gewünschte Ebenen; null = alle lesbaren
     * @return list<CalendarItem>
     */
    public static function for(User $user, CarbonImmutable $from, CarbonImmutable $until, ?array $layers = null, bool $extern = false): array
    {
        $visible = self::visibleLayers($user, $layers);
        $items = [];

        if (in_array(Calendar::EVENTS, $visible, true)) {
            array_push($items, ...self::events($from, $until));
        }
        if (in_array(Calendar::INQUIRIES, $visible, true)) {
            array_push($items, ...self::inquiries($from, $until, $extern));
        }
        $mirrors = array_intersect_key(self::MIRRORS, array_flip($visible));
        $personal = in_array(Calendar::PERSONAL, $visible, true);
        if ($mirrors !== [] || $personal) {
            array_push($items, ...self::duties($user, $from, $until, $mirrors, $personal));
        }
        $entryLayers = array_values(array_diff($visible, Calendar::DERIVED));
        if ($entryLayers !== []) {
            array_push($items, ...self::entries($entryLayers, $from, $until));
        }

        if ($extern) {
            $items = self::forExtern($user, $items);
        }
        usort($items, fn (CalendarItem $a, CalendarItem $b): int => [$a->firstDay, $a->sortKey()] <=> [$b->firstDay, $b->sortKey()]);

        return $items;
    }

    /**
     * Ebenen, die das Konto lesen darf, in der Reihenfolge der Verwaltung.
     *
     * @return Collection<int, Calendar>
     */
    public static function readableLayers(User $user): Collection
    {
        return Calendar::query()->ordered()->get()
            ->filter(fn (Calendar $calendar): bool => $user->access()->canCalendar($calendar->key, Level::Read))
            ->values();
    }

    /**
     * @param  list<string>|null  $layers
     * @return list<string>
     */
    public static function visibleLayers(User $user, ?array $layers): array
    {
        $readable = self::readableLayers($user)->pluck('key')->all();
        $chosen = array_values(array_intersect($readable, $layers ?? []));

        // Nichts (Gültiges) gewählt: alle lesbaren, wie in der PHP-Version
        return $chosen === [] ? $readable : $chosen;
    }

    /** @return list<CalendarItem> */
    private static function events(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $events = Event::query()
            ->with('promoter')
            ->whereNotNull('starts_at')
            ->where('starts_at', '<', $until->addDay())
            ->where(fn (Builder $query): Builder => $query->where('starts_at', '>=', $from->subDays(60))->orWhere('ends_at', '>=', $from))
            ->orderBy('starts_at')
            ->get();

        $items = [];
        foreach ($events as $event) {
            $first = CarbonImmutable::parse($event->starts_at)->startOfDay();
            $last = CarbonImmutable::parse(EventDisplay::lastDay($event));
            if ($last->lt($from) || $first->gt($until)) {
                continue;
            }
            $time = $event->starts_at->format('H:i');
            $promoter = EventDisplay::promoterShort($event);
            $items[] = new CalendarItem(
                uid: 'event-' . $event->id,
                layer: Calendar::EVENTS,
                kind: CalendarItem::EVENT,
                title: (string) $event->title,
                firstDay: $first,
                lastDay: $last,
                time: $time === '00:00' ? null : $time,
                url: EventResource::getUrl('edit', ['record' => $event]),
                tooltip: (string) $event->title . ($promoter !== null ? ' · ' . $promoter : '') . ($last->gt($first) ? ' (mehrtägig)' : ''),
                status: EventDisplay::statusKind($event->status),
                eventId: $event->id,
            );
        }

        return $items;
    }

    /** @return list<CalendarItem> */
    private static function inquiries(CarbonImmutable $from, CarbonImmutable $until, bool $extern): array
    {
        $dates = InquiryDate::query()
            ->with('inquiry.promoter')
            ->whereHas('inquiry', fn (Builder $inquiries): Builder => $inquiries->where('status', Inquiry::OPEN))
            ->where('option_date', '>=', $from->toDateString())
            ->where('option_date', '<', $until->addDay()->toDateString())
            ->get();
        if ($dates->isEmpty()) {
            return [];
        }
        $blocked = Inquiries::blockedDays($from, $until);
        $ranks = Inquiries::ranks($from, $until);

        $items = [];
        foreach ($dates as $date) {
            $day = CarbonImmutable::parse($date->option_date)->startOfDay();
            $rank = $ranks[$day->toDateString()][(int) $date->inquiry_id] ?? null;
            if (isset($blocked[$day->toDateString()]) || $rank === null) {
                continue;
            }
            $inquiry = $date->inquiry;
            $title = Inquiries::barTitle((string) $inquiry->title, $rank);
            $items[] = new CalendarItem(
                uid: 'inquiry-' . $inquiry->id . '-' . $date->id,
                layer: Calendar::INQUIRIES,
                kind: CalendarItem::INQUIRY,
                title: $title,
                firstDay: $day,
                lastDay: $day,
                url: $extern ? null : InquiryResource::getUrl('view', ['record' => $inquiry]),
                tooltip: $title . "\nVeranstalter: " . ($inquiry->promoter?->name ?? '–') . "\nEingang: " . $inquiry->created_at?->format('d.m.Y'),
            );
        }

        return $items;
    }

    /**
     * Dienste aus dem Personal der Events: gespiegelt auf Veranstaltungsleitung
     * und Technik (alle Events, nur mit eingetragener Person), die eigenen
     * auf „Persönlich“ – ein eigener Dienst einmal mit beiden Ebenen. Am ersten
     * Tag des Events, mit den Zeiten der Zuordnung (Ende vor dem Beginn: am
     * Folgetag), ohne Zeiten ganztägig.
     *
     * @param  array<string, list<AssignmentRole>>  $mirrors
     * @return list<CalendarItem>
     */
    private static function duties(User $user, CarbonImmutable $from, CarbonImmutable $until, array $mirrors, bool $personal): array
    {
        $roles = array_merge(...array_values($personal ? self::MIRRORS : $mirrors));
        $assignments = self::assignments($roles, $from, $until)->get();

        $items = [];
        foreach ($assignments as $assignment) {
            $layers = array_keys(array_filter($mirrors, fn (array $layerRoles): bool => in_array($assignment->role, $layerRoles, true)));
            if ($personal && self::isOwn($user, $assignment)) {
                $layers[] = Calendar::PERSONAL;
            }
            if ($layers !== []) {
                $items[] = self::duty($assignment, $assignment->event, $layers);
            }
        }

        return $items;
    }

    /**
     * Die eigenen Dienste im Zeitraum (Rollen aus MIRRORS) – für den
     * persönlichen ICS-Feed (App\Support\PersonalIcsFeed).
     *
     * @return Collection<int, EventAssignment>
     */
    public static function ownAssignments(User $user, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        $employeeId = $user->linkedEmployeeId();
        $tradeId = $user->linkedTradeId();

        return self::assignments(array_merge(...array_values(self::MIRRORS)), $from, $until)
            ->where(function (Builder $query) use ($user, $employeeId, $tradeId): void {
                $query->where(fn (Builder $own): Builder => $own->where('assignee_type', 'user')->where('assignee_id', $user->id));
                if ($employeeId !== null) {
                    $query->orWhere(fn (Builder $own): Builder => $own->where('assignee_type', 'employee')->where('assignee_id', $employeeId));
                }
                if ($tradeId !== null) {
                    $query->orWhere(fn (Builder $own): Builder => $own->where('assignee_type', 'trade')->where('assignee_id', $tradeId));
                }
            })
            ->get()
            ->sortBy(fn (EventAssignment $assignment): string => $assignment->event->starts_at->format('Y-m-d') . ' ' . ($assignment->starts_at ?? '00:00'))
            ->values();
    }

    /**
     * Zuordnungen mit Person zu Events, die im Zeitraum beginnen.
     *
     * @param  list<AssignmentRole>  $roles
     * @return Builder<EventAssignment>
     */
    private static function assignments(array $roles, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return EventAssignment::query()
            ->with(['event', 'assignee'])
            ->whereIn('role', array_map(fn (AssignmentRole $role): string => $role->value, $roles))
            ->where('assignee_id', '>', 0)
            ->whereHas('event', fn (Builder $events): Builder => $events->whereNotNull('starts_at')
                ->where('starts_at', '>=', $from)->where('starts_at', '<', $until->addDay()));
    }

    /** @param  non-empty-list<string>  $layers */
    private static function duty(EventAssignment $assignment, Event $event, array $layers): CalendarItem
    {
        $day = CarbonImmutable::parse($event->starts_at)->startOfDay();
        $start = self::clock($assignment->starts_at);
        $end = self::clock($assignment->ends_at);
        $timed = $start !== null && $end !== null;
        $title = self::LINK . self::assigneeName($assignment) . ' · ' . $assignment->role->getLabel() . ' (' . $event->title . ')';

        return new CalendarItem(
            uid: 'role-' . $assignment->id,
            layer: $layers[0],
            kind: CalendarItem::DUTY,
            title: $title,
            firstDay: $day,
            lastDay: $day,
            time: $timed ? $start : null,
            endTime: $timed ? $end : null,
            url: EventResource::getUrl('edit', ['record' => $event]) . '?phase=planung&bereich=personal',
            tooltip: $title,
            status: EventDisplay::statusKind($event->status),
            eventId: $event->id,
            layers: $layers,
        );
    }

    /** Eigener Dienst: als Konto, als verknüpfter Mitarbeiter (Personen-Konten) oder als verknüpftes Gewerk */
    private static function isOwn(User $user, EventAssignment $assignment): bool
    {
        return match ($assignment->assignee_type) {
            'user' => (int) $assignment->assignee_id === (int) $user->id,
            'employee' => $user->linkedEmployeeId() !== null && (int) $assignment->assignee_id === $user->linkedEmployeeId(),
            'trade' => $user->linkedTradeId() !== null && (int) $assignment->assignee_id === $user->linkedTradeId(),
            default => false,
        };
    }

    private static function assigneeName(EventAssignment $assignment): string
    {
        $assignee = $assignment->assignee;

        return match (true) {
            $assignee instanceof Trade => trim(($assignee->short_name ? $assignee->short_name . ' — ' : '') . $assignee->name),
            $assignee instanceof Employee, $assignee instanceof User => trim($assignee->first_name . ' ' . $assignee->last_name) ?: '–',
            default => '–',
        };
    }

    /**
     * @param  list<string>  $layers
     * @return list<CalendarItem>
     */
    private static function entries(array $layers, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $entries = CalendarEntry::query()
            ->whereIn('calendar_key', $layers)
            ->where('starts_at', '<', $until->addDay())
            ->where(fn (Builder $query): Builder => $query->where('starts_at', '>=', $from)->orWhere('ends_at', '>=', $from))
            ->orderBy('starts_at')
            ->get();

        $items = [];
        foreach ($entries as $entry) {
            $first = CarbonImmutable::parse($entry->starts_at)->startOfDay();
            // Eigene Einträge: Ende einschließlich (anders als bei Events)
            $last = $entry->ends_at !== null && $entry->ends_at->copy()->startOfDay()->gt($first) ? CarbonImmutable::parse($entry->ends_at)->startOfDay() : $first;
            if ($last->lt($from) || $first->gt($until)) {
                continue;
            }
            $items[] = new CalendarItem(
                uid: 'entry-' . $entry->id,
                layer: $entry->calendar_key,
                kind: CalendarItem::ENTRY,
                title: (string) $entry->title,
                firstDay: $first,
                lastDay: $last,
                time: $entry->all_day ? null : $entry->starts_at->format('H:i'),
                endTime: !$entry->all_day && $entry->ends_at !== null ? $entry->ends_at->format('H:i') : null,
                tooltip: (string) $entry->title,
                entryId: $entry->id,
            );
        }

        return $items;
    }

    /**
     * Extern: Links nur zu Events, an denen das Konto beteiligt ist (dann auf
     * „Meine Events“), sonst keiner; „🔗“ fällt weg, eigene Einträge sind nur
     * zum Ansehen.
     *
     * @param  list<CalendarItem>  $items
     * @return list<CalendarItem>
     */
    private static function forExtern(User $user, array $items): array
    {
        $eventIds = array_values(array_unique(array_filter(array_map(fn (CalendarItem $item): ?int => $item->eventId, $items))));
        $involved = $eventIds === [] ? collect() : Involvement::scope(Event::query()->whereIn('id', $eventIds), $user)->pluck('id')->flip();

        return array_map(function (CalendarItem $item) use ($involved): CalendarItem {
            $linked = $item->eventId !== null && $involved->has($item->eventId);
            $title = str_starts_with($item->title, self::LINK) ? substr($item->title, strlen(self::LINK)) : $item->title;

            return $item->with(
                title: $title,
                tooltip: str_starts_with((string) $item->tooltip, self::LINK) ? substr((string) $item->tooltip, strlen(self::LINK)) : $item->tooltip,
                url: $linked ? ExternEventResource::getUrl('view', ['record' => $item->eventId]) : ($item->kind === CalendarItem::ENTRY ? $item->url : null),
                linked: $linked,
            );
        }, $items);
    }

    private static function clock(mixed $time): ?string
    {
        return filled($time) ? substr((string) $time, 0, 5) : null;
    }
}
