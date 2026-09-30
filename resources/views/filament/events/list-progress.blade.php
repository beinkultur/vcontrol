@php
    $progress = \App\Support\EventProgress::phases($getRecord());
@endphp
<div class="vc-mini-progress" title="Buchung {{ $progress['buchung'] }} % · Planung {{ $progress['planung'] }} %">
    <span class="vc-mini-progress__label">B</span>
    <span class="vc-mini-progress__track"><span class="vc-mini-progress__fill vc-fill--buchung" style="width: {{ $progress['buchung'] }}%"></span></span>
    <span class="vc-mini-progress__pct">{{ $progress['buchung'] }}</span>
    <span class="vc-mini-progress__label">P</span>
    <span class="vc-mini-progress__track"><span class="vc-mini-progress__fill vc-fill--planung" style="width: {{ $progress['planung'] }}%"></span></span>
    <span class="vc-mini-progress__pct">{{ $progress['planung'] }}</span>
</div>
