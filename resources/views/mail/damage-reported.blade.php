Es wurde eine neue Schadensmeldung erfasst.

Zeitpunkt: {{ $damage->recorded_at?->format('d.m.Y H:i') }}
Aufgenommen durch: {{ $damage->recorderName() ?? '–' }}
Event: {{ $damage->event?->title ?? '– (allgemeiner Schaden)' }}

Beschreibung:
{{ $damage->description }}

Details: {{ $link }}
