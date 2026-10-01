{{-- Codes Tageszugang wie in der PHP-Version; Stile: public/css/vcontrol.css --}}
@php
    $day = fn ($value): string => $value ? \Illuminate\Support\Carbon::parse($value)->format('d.m.Y') : '–';
@endphp
<x-filament-panels::page>
    <div class="vc-codes">
        <section class="vc-card vc-codes__today">
            <h3 class="vc-card__heading">Aktuell gültiger Code</h3>
            @if ($current)
                <p class="vc-codes__value">{{ $current->display() }}</p>
                <p class="vc-muted">Gültig seit {{ $current->valid_from->format('d.m.Y') }} um {{ $current->valid_from->format('H:i') }} Uhr</p>
                <p class="vc-codes__hint">Das {{ \App\Models\AccessCode::PREFIX }} gehört zur Eingabe am Zugangssystem und muss mit eingegeben werden.</p>
            @else
                <p class="vc-card__empty">Kein Code hinterlegt.</p>
            @endif
        </section>

        <section class="vc-card">
            <h3 class="vc-card__heading">Code nach Datum</h3>
            <label class="vc-codes__lookup">
                <span class="vc-label">Datum</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="datum" />
                </x-filament::input.wrapper>
            </label>
            @if ($lookupDate)
                @if ($lookup)
                    <p class="vc-codes__result">
                        {{ $lookup->valid_on->format('d.m.Y') }}: <strong>{{ $lookup->display() }}</strong>
                        <span class="vc-muted">· ab {{ $lookup->valid_from->format('H:i') }} Uhr</span>
                    </p>
                @else
                    <p class="vc-muted">Für {{ $day($lookupDate) }} ist kein Code hinterlegt.</p>
                @endif
            @elseif ($total > 0)
                <p class="vc-muted">{{ number_format($total, 0, ',', '.') }} Codes hinterlegt ({{ $day($first) }} – {{ $day($last) }}).</p>
            @else
                <p class="vc-muted">Noch keine Codes hinterlegt.</p>
            @endif
        </section>
    </div>

    <section class="vc-card">
        <h3 class="vc-card__heading">Nächste Tage</h3>
        @if ($upcoming->isEmpty())
            <p class="vc-card__empty">Keine kommenden Codes hinterlegt.</p>
        @else
            <div class="vc-codes__table-wrap">
                <table class="vc-codes__table">
                    <thead>
                        <tr><th>Gültig am</th><th>Ab</th><th>Code</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($upcoming as $code)
                            <tr @class(['vc-codes__row--today' => $code->valid_on->isToday()])>
                                <td>{{ $code->valid_on->format('d.m.Y') }}@if ($code->valid_on->isToday()) <span class="vc-muted">· heute</span>@endif</td>
                                <td class="vc-muted">{{ $code->valid_from->format('H:i') }} Uhr</td>
                                <td class="vc-codes__code">{{ $code->display() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-filament-panels::page>
