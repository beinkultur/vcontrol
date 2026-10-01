{{-- Podest-Rechnung unter den Bühnenmaßen wie „Podest-Berechnung“ in der PHP-Version --}}
@php
    $over = $podests['total'] > $inventory;
    $rows = [
        'Hauptbühne' => $podests['main'],
        'Wing SL' => $podests['wing_sl'],
        'Wing SR' => $podests['wing_sr'],
        'Rollipodest' => $podests['rollpodest'],
        'Sonstige' => $podests['other'],
    ];
@endphp
<div @class(['vc-podests', 'vc-podests--alert' => $over])>
    <div class="vc-podests__head">
        <h4 class="vc-podests__title">Podest-Berechnung</h4>
        <span class="vc-podests__inventory">Bestand der Halle: {{ $inventory }} Podeste</span>
    </div>
    <dl class="vc-podests__grid">
        @foreach ($rows as $label => $count)
            <div><dt>{{ $label }}</dt><dd>{{ $count }}</dd></div>
        @endforeach
        <div class="vc-podests__total"><dt>Gesamt</dt><dd>{{ $podests['total'] }}</dd></div>
    </dl>
    <p class="vc-podests__note">
        @if ($over)
            <strong>{{ $podests['total'] }} Podeste</strong> – {{ $podests['total'] - $inventory }} über dem Bestand, Nachbestellung erforderlich.
        @else
            Im Bestand – {{ $inventory - $podests['total'] }} Podeste Reserve.
        @endif
    </p>
</div>
