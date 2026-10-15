{{--
    Zählerstände (App\Filament\Pages\Meters): Zähler als Chips, Jahr blättern,
    Kennzahlen und Diagramme (Widgets in app/Filament/Widgets, bekommen Zähler
    und Jahr fest mit), Events und Monate als Tabellen, darunter die Stände.
    Stile: public/css/vcontrol.css, „Zählerstände“.
--}}
@php
    use App\Support\MeterStats;

    $unit = (string) $meter?->unit;
    $paxSum = array_sum(array_column(array_filter($events, fn (array $row): bool => $row['pax'] !== null), 'pax'));
    $paxAmount = array_sum(array_column(array_filter($events, fn (array $row): bool => $row['pax'] !== null), 'amount'));
@endphp
<x-filament-panels::page>
    @if ($meter === null)
        <section class="vc-card">
            <h3 class="vc-card__heading">Noch keine Zähler</h3>
            <p class="vc-muted">Welche Zählerstände erfasst werden – Strom, Wasser, Fernwärme … –, legt die Venue unter Stammdaten › Zähler fest.</p>
            @if ($manageUrl)
                <p><x-filament::link :href="$manageUrl" wire:navigate>Zähler anlegen</x-filament::link></p>
            @endif
        </section>
    @else
        <div class="vc-meters">
            <div class="vc-meters__toolbar">
                <nav class="vc-meters__chips" aria-label="Zähler">
                    @foreach ($chips as $chip)
                        <a href="{{ $chip['url'] }}" wire:navigate @class(['vc-meters__chip', 'is-active' => $chip['active'], 'is-archived' => $chip['archived']]) @if ($chip['active']) aria-current="page" @endif>{{ $chip['name'] }}@if ($chip['archived']) <span class="vc-muted">(inaktiv)</span>@endif</a>
                    @endforeach
                </nav>
                <div class="vc-meters__years" aria-label="Jahr">
                    @if ($previousUrl)
                        <a href="{{ $previousUrl }}" wire:navigate class="vc-meters__btn" aria-label="Jahr davor">←</a>
                    @else
                        <span class="vc-meters__btn is-disabled" aria-hidden="true">←</span>
                    @endif
                    <span class="vc-meters__year">{{ $year }}</span>
                    @if ($nextUrl)
                        <a href="{{ $nextUrl }}" wire:navigate class="vc-meters__btn" aria-label="Jahr danach">→</a>
                    @else
                        <span class="vc-meters__btn is-disabled" aria-hidden="true">→</span>
                    @endif
                </div>
            </div>

            @livewire(\App\Filament\Widgets\MeterStatsOverview::class, ['meterId' => $meter->id, 'year' => $year], key('meter-stats-' . $meter->id . '-' . $year))

            <div @class(['vc-meters__charts', 'vc-meters__charts--two' => $meter->per_event])>
                @livewire(\App\Filament\Widgets\MeterMonthlyChart::class, ['meterId' => $meter->id, 'year' => $year], key('meter-months-' . $meter->id . '-' . $year))
                @if ($meter->per_event)
                    @livewire(\App\Filament\Widgets\MeterEventsChart::class, ['meterId' => $meter->id, 'year' => $year], key('meter-events-' . $meter->id . '-' . $year))
                @endif
            </div>

            @if ($meter->per_event || $hasMonths)
                <div @class(['vc-meters__tables', 'vc-meters__tables--two' => $meter->per_event && $hasMonths])>
                    @if ($meter->per_event)
                        <section class="vc-card">
                            <h3 class="vc-card__heading">Events {{ $year }}</h3>
                            @if ($events === [])
                                <p class="vc-card__empty">Noch kein Event mit Stand Anfang und Ende.</p>
                            @else
                                <div class="vc-meters__table-wrap">
                                    <table class="vc-meters__table">
                                        <thead>
                                            <tr>
                                                <th>Datum</th>
                                                <th>Event</th>
                                                <th class="is-num is-wide">Anfang</th>
                                                <th class="is-num is-wide">Ende</th>
                                                <th class="is-num">Verbrauch</th>
                                                <th class="is-num">PAX</th>
                                                <th class="is-num">je PAX</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($events as $row)
                                                @php
                                                    $url = $this->eventUrl($row['event']);
                                                @endphp
                                                <tr>
                                                    <td>{{ $row['event']->starts_at->format('d.m.Y') }}</td>
                                                    <td>
                                                        @if ($url)
                                                            <a href="{{ $url }}" wire:navigate class="vc-meters__link">{{ $row['event']->title }}</a>
                                                        @else
                                                            {{ $row['event']->title }}
                                                        @endif
                                                    </td>
                                                    <td class="is-num is-wide">{{ MeterStats::reading($row['start'], $unit) }}</td>
                                                    <td class="is-num is-wide">{{ MeterStats::reading($row['end'], $unit) }}</td>
                                                    <td class="is-num"><strong>{{ MeterStats::amount($row['amount'], $unit) }}</strong></td>
                                                    <td class="is-num">{{ $row['pax'] !== null ? number_format($row['pax'], 0, ',', '.') : '–' }}</td>
                                                    <td class="is-num">{{ MeterStats::amount($row['perPax'], $unit) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="2">{{ count($events) }} {{ count($events) === 1 ? 'Event' : 'Events' }}</td>
                                                <td class="is-wide"></td>
                                                <td class="is-wide"></td>
                                                <td class="is-num">{{ MeterStats::amount(array_sum(array_column($events, 'amount')), $unit) }}</td>
                                                <td class="is-num">{{ $paxSum > 0 ? number_format($paxSum, 0, ',', '.') : '–' }}</td>
                                                <td class="is-num">{{ $paxSum > 0 ? MeterStats::amount($paxAmount / $paxSum, $unit) : '–' }}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            @endif
                        </section>
                    @endif

                    @if ($hasMonths)
                        <section class="vc-card">
                            <h3 class="vc-card__heading">Monate {{ $year }} gegen {{ $year - 1 }}</h3>
                            <div class="vc-meters__table-wrap">
                                <table class="vc-meters__table">
                                    <thead>
                                        <tr>
                                            <th>Monat</th>
                                            <th class="is-num">{{ $year }}</th>
                                            <th class="is-num">{{ $year - 1 }}</th>
                                            <th class="is-num">Veränderung</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($months as $row)
                                            <tr>
                                                <td>{{ $row['label'] }}</td>
                                                <td class="is-num">
                                                    @if ($row['note'] && $row['current'] !== null)
                                                        <span class="vc-muted">{{ $row['note'] }}</span>
                                                    @endif
                                                    {{ MeterStats::amount($row['current'], $unit) }}
                                                </td>
                                                <td class="is-num vc-muted">
                                                    @if ($row['previousNote'] && $row['previous'] !== null)
                                                        {{ $row['previousNote'] }}
                                                    @endif
                                                    {{ MeterStats::amount($row['previous'], $unit) }}
                                                </td>
                                                <td class="is-num">{{ $row['change'] ?? '–' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    @endif
                </div>
            @endif

            {{ $this->table }}
        </div>
    @endif
</x-filament-panels::page>
