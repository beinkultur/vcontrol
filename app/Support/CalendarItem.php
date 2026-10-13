<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Ein Eintrag im Kalender, egal woher: Event, Option einer Anfrage, Dienst aus
 * dem Personal oder eigener Eintrag (App\Support\CalendarItems). Tage
 * einschließlich; Uhrzeit nur, wenn es eine gibt (sonst ganztägig).
 */
final class CalendarItem
{
    public const EVENT = 'event';

    public const INQUIRY = 'inquiry';

    public const DUTY = 'duty';

    public const ENTRY = 'entry';

    public function __construct(
        public readonly string $uid,
        public readonly string $layer,
        public readonly string $kind,
        public readonly string $title,
        public readonly CarbonImmutable $firstDay,
        public readonly CarbonImmutable $lastDay,
        public readonly ?string $time = null,
        public readonly ?string $endTime = null,
        public readonly ?string $url = null,
        public readonly ?string $tooltip = null,
        /** 'fraglich' | 'cancelled' | null – wie EventDisplay::statusKind */
        public readonly ?string $status = null,
        public readonly ?int $entryId = null,
        public readonly ?int $eventId = null,
        /** Extern: Link zum eigenen Event (fett, mit ↗) */
        public readonly bool $linked = false,
        /**
         * Alle Ebenen, auf denen der Eintrag steht, die erste ist $layer – mehr als
         * eine bei eigenen Diensten (z. B. Veranstaltungsleitung und Persönlich).
         *
         * @var list<string>
         */
        public readonly array $layers = [],
    ) {
    }

    public function with(?string $title = null, ?string $tooltip = null, ?string $url = null, ?bool $linked = null): self
    {
        return new self($this->uid, $this->layer, $this->kind, $title ?? $this->title, $this->firstDay, $this->lastDay, $this->time,
            $this->endTime, $url, $tooltip ?? $this->tooltip, $this->status, $this->entryId, $this->eventId, $linked ?? $this->linked, $this->layers);
    }

    /** @return non-empty-list<string> die Ebenen für die Farbe, mindestens $layer */
    public function colorLayers(): array
    {
        return $this->layers !== [] ? $this->layers : [$this->layer];
    }

    public function isAllDay(): bool
    {
        return $this->time === null;
    }

    /**
     * Vorrang je Tag: Events vor Optionen, eigenen Einträgen und Diensten – in
     * Monat und 4 Wochen steht so das Event oben, nicht hinter seinen Diensten.
     */
    public function rank(): int
    {
        return match ($this->kind) {
            self::EVENT => 0,
            self::INQUIRY => 1,
            self::ENTRY => 2,
            default => 3,
        };
    }

    /** Sortierung innerhalb des Vorrangs wie in der PHP-Version: Uhrzeit (ganztägig = 00:00), dann Titel */
    public function sortKey(): string
    {
        return ($this->time ?? '00:00') . ' ' . mb_strtolower($this->title);
    }

    /** Beschriftung im Raster: „20:00 Titel“ oder nur der Titel */
    public function label(): string
    {
        return ($this->time !== null ? $this->time . ' ' : '') . $this->title;
    }

    /** Zeitangabe für Liste und Tag: „20:00“, „08:00–16:00“ oder leer */
    public function timeLabel(): string
    {
        if ($this->time === null) {
            return '';
        }

        return $this->endTime !== null ? $this->time . '–' . $this->endTime : $this->time;
    }

    /** Minuten ab 0:00 für das Zeitraster; Ende vor dem Beginn = nach Mitternacht (bis 24:00) */
    public function minutes(): ?array
    {
        if ($this->time === null) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', $this->time));
        $start = $h * 60 + $m;
        if ($this->endTime === null) {
            return [$start, min(24 * 60, $start + 60)];
        }
        [$h, $m] = array_map('intval', explode(':', $this->endTime));
        $end = $h * 60 + $m;

        return [$start, $end <= $start ? 24 * 60 : $end];
    }
}
