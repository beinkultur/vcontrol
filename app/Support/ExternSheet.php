<?php

namespace App\Support;

use App\Enums\AssignmentRole;
use App\Enums\ServiceCode;
use App\Filament\Resources\Events\Schemas\EventForm;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\EventFile;
use App\Models\EventNote;
use App\Models\EventService;
use App\Models\Setting;
use Closure;

/**
 * Was Externe von einem Event sehen – im Extern-Bereich „Meine Events“ und im
 * Daysheet. Eine Stelle für beide: Der genaue Inhalt des Daysheets wird noch
 * festgelegt und dann hier angepasst.
 *
 * Bewusst nicht dabei: Buchhaltung, Buchung (PAX, interne Notizen), Gäste,
 * Betrieb, WLAN. Dateien und Notizen ohne die „für Externe verborgenen“.
 */
final class ExternSheet
{
    /** Ansprechpartner aus dem Personal */
    private const CONTACT_ROLES = [AssignmentRole::ProjectLead, AssignmentRole::HouseRepEarly, AssignmentRole::HouseRepLate];

    /**
     * @param  Closure(EventFile): string  $fileUrl  Download-Adresse je nach Zugang (Konto oder Daysheet-Link)
     * @return array{title: string, date: string, promoter: ?string, venue: string, cancelled: bool,
     *     times: list<array{label: string, value: string}>,
     *     contacts: list<array{label: string, name: string, time: ?string}>,
     *     services: list<array{label: string, responsible: ?string, provider: ?string, note: ?string}>,
     *     stage: array{summary: string, rows: array<string, string>, notes: ?string, svg: string}|null,
     *     files: array<string, list<array{name: string, stand: string, size: string, url: string}>>,
     *     notes: list<array{subject: string, body: string, date: ?string, author: ?string}>}
     */
    public static function make(Event $event, Closure $fileUrl): array
    {
        $event->loadMissing(['promoter', 'schedule', 'stage', 'services.trade', 'assignments.assignee']);

        return [
            'title' => (string) $event->title,
            'date' => self::date($event),
            'promoter' => $event->promoter?->name,
            'venue' => Setting::lookup(Setting::VENUE_NAME) ?: (string) config('app.name'),
            'cancelled' => EventDisplay::statusKind($event->status) === 'cancelled',
            'times' => self::times($event),
            'contacts' => self::contacts($event),
            'services' => self::services($event),
            'stage' => self::stage($event),
            'files' => self::files($event, $fileUrl),
            'notes' => self::notes($event),
        ];
    }

    /** „Fr, 09.10.2026“, mehrtägig mit Enddatum */
    public static function date(Event $event): string
    {
        $start = $event->starts_at;
        if ($start === null) {
            return 'Datum offen';
        }
        $label = EventDisplay::weekday($start) . ', ' . $start->format('d.m.Y');
        if (EventDisplay::isMultiDay($event)) {
            $end = $event->ends_at->copy()->subDay();
            $label .= ' – ' . EventDisplay::weekday($end) . ', ' . $end->format('d.m.Y');
        }

        return $label;
    }

    /** @return list<array{label: string, value: string}> */
    private static function times(Event $event): array
    {
        $rows = [];
        foreach (EventForm::TIMES as $field => $label) {
            $value = self::time($event->schedule?->getAttribute($field));
            if ($value !== null) {
                $rows[] = ['label' => $label, 'value' => $value];
            }
        }

        return $rows;
    }

    /** @return list<array{label: string, name: string, time: ?string}> */
    private static function contacts(Event $event): array
    {
        $rows = [];
        foreach (self::CONTACT_ROLES as $role) {
            $event->assignments
                ->filter(fn (EventAssignment $assignment): bool => $assignment->role === $role)
                ->each(function (EventAssignment $assignment) use (&$rows, $role): void {
                    $from = self::time($assignment->starts_at);
                    $until = self::time($assignment->ends_at);
                    $rows[] = [
                        'label' => $role->getLabel(),
                        'name' => $assignment->assigneeName(),
                        'time' => match (true) {
                            $from !== null && $until !== null => $from . '–' . $until,
                            $from !== null => 'ab ' . $from,
                            $until !== null => 'bis ' . $until,
                            default => null,
                        },
                    ];
                });
        }
        if (filled($event->onsite_contact)) {
            $rows[] = ['label' => 'Ansprechpartner vor Ort', 'name' => (string) $event->onsite_contact, 'time' => null];
        }

        return $rows;
    }

    /** @return list<array{label: string, responsible: ?string, provider: ?string, note: ?string}> */
    private static function services(Event $event): array
    {
        $order = array_flip(array_map(fn (ServiceCode $code): string => $code->value, ServiceCode::cases()));

        return $event->services
            ->filter(fn (EventService $service): bool => $service->responsible !== null || $service->trade_id !== null
                || filled($service->provider_label) || filled($service->note))
            ->sortBy(fn (EventService $service): int => $order[$service->service->value] ?? 999)
            ->map(fn (EventService $service): array => [
                'label' => $service->service->getLabel(),
                'responsible' => $service->responsible?->getLabel(),
                'provider' => $service->providerName(),
                'note' => filled($service->note) ? (string) $service->note : null,
            ])
            ->values()
            ->all();
    }

    /** @return array{summary: string, rows: array<string, string>, notes: ?string, svg: string}|null */
    private static function stage(Event $event): ?array
    {
        $stage = $event->stage;
        $summary = StagePodests::summary($stage)['text'];
        if ($stage === null || $summary === '–') {
            return null;
        }

        $total = StagePodests::total($stage);
        $rows = array_filter([
            'Hauptbühne' => self::dimensions($stage->width, $stage->depth),
            'Höhe' => (float) $stage->height > 0 ? StagePodests::formatMeters((float) $stage->height) . ' m' : null,
            'Wing stage left' => self::dimensions($stage->wing_sl_width, $stage->wing_sl_depth),
            'Wing stage right' => self::dimensions($stage->wing_sr_width, $stage->wing_sr_depth),
            'Rollipodest' => self::dimensions($stage->rollpodest_width, $stage->rollpodest_depth),
            'Sonstige Podeste' => (int) $stage->extra_platforms > 0 ? $stage->extra_platforms . ' Stück' : null,
            'Podeste gesamt' => $total > 0 ? $total . ' Stück' : null,
        ]);

        return [
            'summary' => $summary,
            'rows' => $rows,
            'notes' => filled($stage->stage_notes) ? (string) $stage->stage_notes : null,
            'svg' => StagePlan::toSvg(StagePlan::build($stage, $event)),
        ];
    }

    /** @return array<string, list<array{name: string, stand: string, size: string, url: string}>> */
    private static function files(Event $event, Closure $fileUrl): array
    {
        $files = $event->files()->with('tag')->where('event_files.hidden_from_externals', false)->get()
            ->concat($event->linkedFiles()->with('tag')->where('event_files.hidden_from_externals', false)->get());

        return $files
            ->sortBy(fn (EventFile $file): string => sprintf('%05d %s', $file->tag?->sort_order ?? 99999, mb_strtolower($file->displayName())))
            ->groupBy(fn (EventFile $file): string => $file->tag?->name ?? 'Sonstiges')
            ->map(fn ($group): array => $group->map(fn (EventFile $file): array => [
                'name' => $file->displayName(),
                'stand' => $file->standLabel(),
                'size' => $file->sizeLabel(),
                'url' => $fileUrl($file),
            ])->values()->all())
            ->all();
    }

    /** @return list<array{subject: string, body: string, date: ?string, author: ?string}> */
    private static function notes(Event $event): array
    {
        return $event->notes()
            ->where('hidden_from_externals', false)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EventNote $note): array => [
                'subject' => (string) $note->subject,
                'body' => (string) $note->body,
                'date' => $note->updated_at?->format('d.m.Y'),
                'author' => $note->created_by_name,
            ])
            ->all();
    }

    /** Ist die Datei für Externe dieses Events sichtbar (direkt oder übergreifend angehängt)? */
    public static function showsFile(Event $event, EventFile $file): bool
    {
        if ($file->hidden_from_externals) {
            return false;
        }

        return (int) $file->event_id === (int) $event->getKey()
            || ($file->is_shared && $file->linkedEvents()->whereKey($event->getKey())->exists());
    }

    private static function dimensions(mixed $width, mixed $depth): ?string
    {
        $width = (float) $width;
        $depth = (float) $depth;
        if ($width <= 0 || $depth <= 0) {
            return null;
        }

        return StagePodests::formatMeters($width) . ' × ' . StagePodests::formatMeters($depth) . ' m';
    }

    private static function time(mixed $value): ?string
    {
        return filled($value) ? substr((string) $value, 0, 5) : null;
    }
}
