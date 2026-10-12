{{-- Event-Liste, Spalte „Fortschritt“: Buchung, Planung, Durchführung in den Farben des Farbschemas (vcontrol.css: .vc-fill--*) --}}
@php
    $progress = \App\Support\EventProgress::phases($getRecord());
    $phases = ['buchung' => ['B', 'Buchung'], 'planung' => ['P', 'Planung'], 'durchfuehrung' => ['D', 'Durchführung']];
@endphp
<div class="vc-mini-progress" title="{{ collect($phases)->map(fn (array $phase, string $key): string => $phase[1] . ' ' . $progress[$key] . ' %')->implode(' · ') }}">
    @foreach ($phases as $key => [$letter])
        <span class="vc-mini-progress__label">{{ $letter }}</span>
        <span class="vc-mini-progress__track"><span class="vc-mini-progress__fill vc-fill--{{ $key }}" style="width: {{ $progress[$key] }}%"></span></span>
        <span class="vc-mini-progress__pct">{{ $progress[$key] }}</span>
    @endforeach
</div>
