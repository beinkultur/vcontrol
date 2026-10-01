{{-- Übersicht des Event-Workspace wie das Dashboard der PHP-Version. Stile: public/css/vcontrol.css --}}
@php
    $progress = \App\Support\EventProgress::phases($event);
    $guestCount = $event->guests()->count();
    $guestTickets = (int) $event->guests()->sum('free_tickets');
    $services = \App\Support\EventProgress::filledServices($event);
    // Dateien direkt am Event und übergreifende, nach Tag gruppiert wie in der PHP-Version
    $files = $event->files()->with('tag')->get()->each(fn ($file) => $file->setAttribute('linked', false))
        ->concat($event->linkedFiles()->with('tag')->get()->each(fn ($file) => $file->setAttribute('linked', true)));
    $filesByTag = $files
        ->sortBy(fn ($file) => sprintf('%05d %s', $file->tag?->sort_order ?? 99999, mb_strtolower($file->displayName())))
        ->groupBy(fn ($file) => $file->tag?->name ?? 'Sonstiges');
    $areas = \App\Support\EventProgress::planningAreas($event, $guestCount, $files->count());
    $slips = $event->orderSlips()->with('items')->get();
    $openHandovers = $event->handoverProtocols()->where('status', \App\Models\HandoverProtocol::OPEN)->count();
    $areaLabels = ['zeiten' => 'Zeiten', 'checkliste' => 'Checkliste', 'buehne' => 'Bühne', 'personal' => 'Personal', 'gewerke' => 'Gewerke', 'gaeste' => 'Gästeliste', 'dateien' => 'Dateien', 'sonstiges' => 'Sonstiges'];
    $user = auth()->user();
    $seesFinance = $user instanceof \App\Models\User && $user->access()->can(\App\Access\Area::Buchhaltung);
    $time = fn ($value): ?string => filled($value) ? substr((string) $value, 0, 5) : null;
    $stage = \App\Support\StagePodests::summary($event->stage)['text'];
    $link = fn (string $phase, ?string $section = null): string => $pageUrl . '?phase=' . $phase . ($section ? '&bereich=' . $section : '');

    $cards = [
        'buchung' => ['num' => 1, 'title' => 'Buchung', 'lines' => array_filter([
            $seesFinance && filled($event->finance?->contract_status) ? 'Vertrag: ' . $event->finance->contract_status : null,
            $seesFinance && filled($event->finance?->price_list) ? 'Preisliste: ' . $event->finance->price_list : null,
            filled($event->pr?->pr_status) ? 'PR: ' . $event->pr->pr_status : null,
            $event->hasFinanceAlert() ? 'Buchhaltung: offen' : null,
        ])],
        'planung' => ['num' => 2, 'title' => 'Planung', 'lines' => array_filter([
            $time($event->schedule?->start_time) ? 'Beginn ' . $time($event->schedule->start_time) : null,
            $time($event->schedule?->admission) ? 'Einlass ' . $time($event->schedule->admission) : null,
            $stage !== '–' ? 'Bühne ' . $stage : null,
            $services > 0 ? $services . ' ' . ($services === 1 ? 'Gewerk' : 'Gewerke') : null,
            $guestCount > 0 ? $guestCount . ' Gäste · ' . $guestTickets . ' Tickets' : null,
            count(array_filter($areas)) . ' / ' . count($areas) . ' Bereiche begonnen',
        ])],
        'durchfuehrung' => ['num' => 3, 'title' => 'Durchführung', 'lines' => array_filter([
            filled($event->status) ? 'Status ' . \App\Support\EventDisplay::statusLabel($event->status) : null,
            $time($event->schedule?->start_time) ? 'Show ' . $time($event->schedule->start_time) : null,
            $event->pax !== null ? 'PAX abgerechnet ' . number_format($event->pax, 0, ',', '.') : null,
            $slips->isNotEmpty() ? $slips->count() . ' ' . ($slips->count() === 1 ? 'Bestellschein' : 'Bestellscheine') . ' · ' . \App\Models\OrderSlip::money($slips->sum(fn ($slip) => $slip->total())) : null,
            $openHandovers > 0 ? $openHandovers . ' ' . ($openHandovers === 1 ? 'Übergabe' : 'Übergaben') . ' offen' : null,
            $event->closed ? 'Event abgeschlossen' : null,
        ])],
    ];

    $facts = [
        'Veranstalter' => \App\Support\EventDisplay::promoterShort($event),
        'Projektleitung' => \App\Support\EventDisplay::projectLeadName($event),
        'VA-Status' => filled($event->status) ? \App\Support\EventDisplay::statusLabel($event->status) : null,
        'PAX erwartet' => $event->pax_expected !== null ? number_format($event->pax_expected, 0, ',', '.') : null,
        'Bereiche' => implode(', ', (array) $event->areas),
        'Bestuhlung' => implode(', ', (array) $event->seating),
        'VA-Kategorie' => collect([$event->event_type1, $event->event_type2])->filter()->implode(' · '),
    ];
@endphp

<div class="vc-dash">
    @if ($event->hasFinanceAlert())
        <div class="vc-dash__alert" role="status">
            Buchhaltung: Zahlungsstatus fehlt – das Event beginnt in weniger als 14 Tagen.
        </div>
    @endif

    <div class="vc-dash__phases">
        @foreach ($cards as $phase => $card)
            <a href="{{ $link($phase) }}" wire:navigate class="vc-card vc-card--{{ $phase }}">
                <div class="vc-card__head">
                    <span class="vc-card__num">{{ $card['num'] }}</span>
                    <h3 class="vc-card__title">{{ $card['title'] }}</h3>
                    <span class="vc-card__pct">{{ $progress[$phase] }} %</span>
                </div>
                <span class="vc-progress"><span class="vc-progress__fill vc-fill--{{ $phase }}" style="width: {{ $progress[$phase] }}%"></span></span>
                @if ($card['lines'] !== [])
                    <ul class="vc-card__list">
                        @foreach ($card['lines'] as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="vc-card__empty">Noch keine Angaben.</p>
                @endif
                <span class="vc-card__cta">Öffnen ›</span>
            </a>
        @endforeach
    </div>

    <div class="vc-dash__facts">
        <section class="vc-card">
            <h3 class="vc-card__heading">Stammdaten</h3>
            <dl class="vc-facts">
                @foreach ($facts as $label => $value)
                    <div>
                        <dt>{{ $label }}</dt>
                        <dd>{{ filled($value) ? $value : '–' }}</dd>
                    </div>
                @endforeach
            </dl>
            <a href="{{ $link('buchung', 'daten') }}" wire:navigate class="vc-card__link">Zu den Daten ›</a>
        </section>

        <section class="vc-card">
            <h3 class="vc-card__heading">Planungsbereiche</h3>
            <ul class="vc-areas">
                @foreach ($areas as $section => $started)
                    <li>
                        <a href="{{ $link('planung', $section) }}" wire:navigate>
                            <span @class(['vc-areas__dot', 'vc-areas__dot--done' => $started])></span>
                            {{ $areaLabels[$section] }}
                        </a>
                    </li>
                @endforeach
            </ul>
            <a href="{{ \App\Filament\Resources\Events\EventResource::getUrl('stage-plan', ['record' => $event]) }}" wire:navigate class="vc-card__link">Bühnenplan anzeigen ›</a>
        </section>

        <section class="vc-card vc-card--span">
            <h3 class="vc-card__heading">Dateien</h3>
            @if ($files->isEmpty())
                <p class="vc-card__empty">Noch keine Dateien hinterlegt.</p>
            @else
                <div class="vc-files">
                    @foreach ($filesByTag as $tag => $tagFiles)
                        <div class="vc-files__group">
                            <h4 class="vc-files__tag">{{ $tag }}</h4>
                            <ul class="vc-files__list">
                                @foreach ($tagFiles as $file)
                                    <li>
                                        <a href="{{ $file->downloadUrl() }}" target="_blank" rel="noopener" class="vc-files__name">{{ $file->displayName() }}</a>
                                        <span class="vc-muted">· {{ $file->standLabel() }}@if ($file->linked) · übergreifend @endif</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
            <a href="{{ $link('planung', 'dateien') }}" wire:navigate class="vc-card__link">Dateien verwalten ›</a>
        </section>
    </div>
</div>
