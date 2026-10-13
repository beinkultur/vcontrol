{{-- Summen im Costing-Formular, live aus den Posten (App\Support\Costings::totals). Stil: public/css/vcontrol.css --}}
@php
    $money = fn (float $amount): string => \App\Support\Costings::money($amount);
@endphp
<div class="vc-costing-totals">
    <dl>
        <div>
            <dt>Summe netto</dt>
            <dd>{{ $money($totals['net']) }}</dd>
        </div>
        <div>
            <dt>zzgl. {{ \App\Support\Costings::number($vatRate) }} % MwSt.</dt>
            <dd>{{ $money($totals['vat']) }}</dd>
        </div>
        <div class="vc-costing-totals__gross">
            <dt>Gesamt brutto</dt>
            <dd>{{ $money($totals['gross']) }}</dd>
        </div>
    </dl>
    @if ($totals['open'] > 0)
        <p class="vc-muted">{{ $totals['open'] === 1 ? '1 Posten' : $totals['open'] . ' Posten' }} nach Aufwand, nicht in der Summe.</p>
    @endif
</div>
