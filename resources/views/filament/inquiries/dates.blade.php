{{-- Wunschtermine einer Anfrage mit Rang (1️⃣ Option …) oder „Nicht verfügbar“, in Liste und Ansicht (App\Support\Inquiries) --}}
@php
    $slots = \App\Support\Inquiries::slots($inquiry);
@endphp
<ul class="vc-inquiry-dates">
    @foreach ($inquiry->dates as $date)
        @php
            $slot = $slots[$date->id] ?? null;
            $confirmed = (int) $inquiry->confirmed_date_id === (int) $date->id;
        @endphp
        <li @class(['vc-inquiry-dates__blocked' => $slot !== null && !$slot['available'] && $inquiry->isOpen(), 'vc-inquiry-dates__confirmed' => $confirmed])>
            <span class="vc-inquiry-dates__day">{{ \App\Support\EventDisplay::weekday($date->option_date) }} {{ $date->option_date->format('d.m.Y') }}</span>
            @if ($confirmed)
                <span class="vc-inquiry-dates__label">bestätigt</span>
            @elseif ($inquiry->isOpen() && $slot !== null)
                <span class="vc-inquiry-dates__label">{{ $slot['label'] }}</span>
            @endif
        </li>
    @endforeach
</ul>
