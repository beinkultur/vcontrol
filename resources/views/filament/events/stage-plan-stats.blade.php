{{-- Zahlen und Legende neben dem Bühnenplan wie in der PHP-Version --}}
@php
    $stats = $plan['stats'];
    $tiers = $stats['tier_counts'];
@endphp
<div class="vc-plan-stats">
    <dl class="vc-facts">
        <div><dt>Podeste im Plan</dt><dd><strong>{{ $stats['plan_used'] }}</strong></dd></div>
        <div><dt>Verfügbar im Plan</dt><dd>{{ $stats['available'] }} <span class="vc-muted">({{ $stats['inventory'] }} − Rollipodest)</span></dd></div>
        <div><dt>Gesamt inkl. Rollipodest</dt><dd>{{ $stats['total_used'] }} / {{ $stats['inventory'] }}</dd></div>
        @if ($stats['roll_reserved'] > 0)
            <div><dt>Rollipodest</dt><dd>{{ $stats['roll_reserved'] }} <span class="vc-muted">nicht im Plan</span></dd></div>
        @endif
        @if ($stats['extra_other'] > 0)
            <div><dt>Sonstige Podeste</dt><dd>{{ $stats['extra_other'] }}</dd></div>
        @endif
        @if ($stats['over_limit'])
            <div><dt>Nachbestellung</dt><dd class="vc-text-danger"><strong>{{ $stats['additional'] }}</strong></dd></div>
        @endif
    </dl>

    <ul class="vc-plan-legend">
        <li><span class="vc-swatch" style="background:#d4d4d4;border-color:#737373"></span> Standardbühne {{ $plan['base']['width'] }}×{{ $plan['base']['depth'] }} ({{ $tiers['standard'] }} / {{ $plan['base']['podests'] }})</li>
        <li><span class="vc-swatch" style="background:#93c5fd;border-color:#2563eb"></span> Hausbestand ({{ $tiers['in_house'] }} / max. {{ $plan['house']['2x1'] }} + {{ $plan['house']['1x1'] }}×1×1)</li>
        <li><span class="vc-swatch" style="background:#fdba74;border-color:#ea580c"></span> angemietet ({{ $tiers['rented'] }})</li>
        <li><span class="vc-swatch" style="background:#c4b5fd;border-color:#6d28d9"></span> Treppen</li>
        <li><span class="vc-swatch vc-swatch--line" style="border-color:#dc2626"></span> Raummitte</li>
        <li><span class="vc-swatch vc-swatch--line" style="border-color:#16a34a"></span> Raumbegrenzung ±{{ \App\Support\StagePodests::formatMeters($plan['room']['boundary']) }} m</li>
        <li><span class="vc-swatch vc-swatch--line" style="border-color:#000"></span> Rückwand ({{ $plan['stage']['backwall_cm'] }} cm)</li>
    </ul>
</div>
