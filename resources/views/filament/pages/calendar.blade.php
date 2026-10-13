{{--
    Kalender (App\Filament\Pages\Calendar, ExternCalendar): Werkzeugleiste mit
    Blättern und Ansicht, Ebenen als Chips (Farbe der Kalender-Ebene), Raster mit
    Balken (Monat, 4 Wochen; je Woche höchstens Calendar::MAX_LANES Bahnen, der
    Rest als „+n weitere“) bzw. Ganztägig-Leiste und Zeitraster (Woche, Tag),
    am Handy eine Liste der Tage. Stile: public/css/vcontrol.css, „Kalender“.
--}}
@php
    $grid = in_array($cal->view, [\App\Support\CalendarView::WEEK, \App\Support\CalendarView::DAY], true);
    $columns = $cal->view === \App\Support\CalendarView::DAY ? 1 : 7;
@endphp
<x-filament-panels::page>
    <div @class(['vc-cal', 'vc-cal--' . $cal->view, 'vc-cal--extern' => $extern])>
        <div class="vc-cal__toolbar">
            <div class="vc-cal__nav">
                <a href="{{ $urls['previous'] }}" wire:navigate class="vc-cal__btn" aria-label="Zurück">←</a>
                <a href="{{ $urls['today'] }}" wire:navigate class="vc-cal__btn">Heute</a>
                <a href="{{ $urls['next'] }}" wire:navigate class="vc-cal__btn" aria-label="Weiter">→</a>
                <h2 class="vc-cal__title">{{ $cal->title() }}</h2>
            </div>
            <nav class="vc-cal__views" aria-label="Ansicht">
                @foreach (\App\Support\CalendarView::LABELS as $view => $label)
                    <a href="{{ $urls['views'][$view] }}" wire:navigate @class(['vc-cal__view', 'is-active' => $cal->view === $view]) @if ($cal->view === $view) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </div>

        @if ($showLayers)
            <div class="vc-cal__layers" aria-label="Ebenen">
                @foreach ($layers as $layer)
                    <a href="{{ $layer['url'] }}" wire:navigate @class(['vc-cal__layer', 'is-active' => $layer['active']]) style="--vc-cal-color: {{ $layer['color'] }}" aria-pressed="{{ $layer['active'] ? 'true' : 'false' }}">{{ $layer['name'] }}</a>
                @endforeach
            </div>
        @endif

        <div class="vc-cal__grid" style="--vc-cal-columns: {{ $columns }}">
            @if ($cal->view !== \App\Support\CalendarView::DAY)
                <div class="vc-cal__head">
                    @foreach ($rows[0]['days'] as $day)
                        <div @class(['vc-cal__headday', 'is-today' => $grid && $day->isSameDay($today)])>
                            {{ \App\Support\CalendarView::WEEKDAYS[$day->dayOfWeekIso - 1] }}@if ($grid) <span>{{ $day->format('d.m.') }}</span>@endif
                        </div>
                    @endforeach
                </div>
            @endif

            @foreach ($rows as $row)
                <div class="vc-cal__week">
                    <div class="vc-cal__bg" aria-hidden="true">
                        @foreach ($row['days'] as $day)
                            <div @class(['vc-cal__cell', 'is-today' => $day->isSameDay($today), 'is-outside' => !$cal->contains($day), 'is-weekend' => $day->isWeekend()])></div>
                        @endforeach
                    </div>
                    @unless ($grid)
                        <div class="vc-cal__dates">
                            @foreach ($row['days'] as $day)
                                <div @class(['vc-cal__date', 'is-today' => $day->isSameDay($today)])>
                                    @if ($cal->contains($day)){{ $day->day }}@endif
                                </div>
                            @endforeach
                        </div>
                    @endunless

                    @if ($row['lanes'] !== [])
                        <div @class(['vc-cal__lanes', 'vc-cal__lanes--allday' => $grid])>
                            @if ($grid)
                                <span class="vc-cal__allday-label">Ganztägig</span>
                            @endif
                            @foreach ($row['lanes'] as $lane)
                                <div class="vc-cal__lane">
                                    @foreach ($lane as $segment)
                                        @include('filament.calendar.item', [
                                            'item' => $segment['item'],
                                            'class' => 'vc-cal__bar',
                                            'modifiers' => ['vc-cal__bar--from-before' => $segment['fromBefore'], 'vc-cal__bar--goes-on' => $segment['goesOn']],
                                            'style' => 'grid-column: ' . $segment['column'] . ' / span ' . $segment['span'],
                                            'text' => $segment['item']->label(),
                                        ])
                                    @endforeach
                                </div>
                            @endforeach
                            @if ($row['more'] !== [])
                                <div class="vc-cal__lane">
                                    @foreach ($row['more'] as $more)
                                        <a href="{{ $more['url'] }}" wire:navigate class="vc-cal__more" style="grid-column: {{ $more['column'] }}">+{{ $more['count'] }} weitere</a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($row['time'] !== null)
                        <div class="vc-cal__time" style="--vc-cal-hours: {{ $row['time']['until'] - $row['time']['from'] }}">
                            <div class="vc-cal__hours" aria-hidden="true">
                                @for ($hour = $row['time']['from']; $hour < $row['time']['until']; $hour++)
                                    <span>{{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}:00</span>
                                @endfor
                            </div>
                            <div class="vc-cal__columns">
                                @foreach ($row['time']['days'] as $blocks)
                                    <div class="vc-cal__column">
                                        @foreach ($blocks as $block)
                                            @include('filament.calendar.item', [
                                                'item' => $block['item'],
                                                'class' => 'vc-cal__block',
                                                'modifiers' => [],
                                                'style' => 'top: ' . $block['top'] . '%; height: ' . $block['height'] . '%; left: ' . $block['left'] . '%; width: ' . $block['width'] . '%',
                                                'text' => $block['item']->label(),
                                            ])
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Am Handy: die Tage mit Einträgen untereinander --}}
        <div class="vc-cal__agenda">
            @forelse ($agenda as $entry)
                <section class="vc-cal__agenda-day">
                    <h3 @class(['is-today' => $entry['day']->isSameDay($today)])>{{ \App\Support\EventDisplay::weekday($entry['day']) }}, {{ $entry['day']->format('d.m.') }}</h3>
                    @foreach ($entry['items'] as $item)
                        @include('filament.calendar.item', [
                            'item' => $item,
                            'class' => 'vc-cal__row',
                            'modifiers' => [],
                            'style' => '',
                            'text' => trim($item->timeLabel() . ' ' . $item->title),
                        ])
                    @endforeach
                </section>
            @empty
                <p class="vc-muted">Keine Einträge in diesem Zeitraum.</p>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
