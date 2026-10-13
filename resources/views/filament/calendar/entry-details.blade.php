{{-- Eigener Kalender-Eintrag zum Ansehen (ohne Recht, die Ebene zu bearbeiten) --}}
<dl class="vc-cal-entry">
    <dt>Ebene</dt>
    <dd>{{ $entry->calendar?->name ?? $entry->calendar_key }}</dd>
    <dt>Wann</dt>
    <dd>
        @if ($entry->all_day)
            {{ $entry->starts_at->format('d.m.Y') }}@if ($entry->ends_at && !$entry->ends_at->isSameDay($entry->starts_at)) – {{ $entry->ends_at->format('d.m.Y') }}@endif, ganztägig
        @else
            {{ $entry->starts_at->format('d.m.Y, H:i') }}@if ($entry->ends_at) – {{ $entry->ends_at->isSameDay($entry->starts_at) ? $entry->ends_at->format('H:i') : $entry->ends_at->format('d.m.Y, H:i') }}@endif Uhr
        @endif
    </dd>
    @if (filled($entry->description))
        <dt>Beschreibung</dt>
        <dd>{!! nl2br(e($entry->description), false) !!}</dd>
    @endif
</dl>
