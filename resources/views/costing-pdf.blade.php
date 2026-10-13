{{--
    Costing als PDF (App\Support\CostingPdf, Dompdf): Dompdf kann nur CSS 2.1 –
    deshalb Tabellen statt Flex/Grid, Schrift DejaVu Sans (hat €, Umlaute).
    Seitenzahlen setzt CostingPdf, der Fuß steht fest am Seitenende.
--}}
@php
    $venueLogo ??= null;
    $money = fn (float $amount): string => \App\Support\Costings::money($amount);
    $number = fn (float $value): string => \App\Support\Costings::number($value);
    $priceList = $costing->priceList;
    $open = 0;
@endphp
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $costing->fileName() }}</title>
    <style>
        @page { margin: 16mm 16mm 24mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #1a1a1a; line-height: 1.35; }
        .venue { font-size: 10pt; font-weight: bold; letter-spacing: 0.04em; text-transform: uppercase; color: #555; }
        h1 { font-size: 17pt; margin: 1mm 0 0; }
        .head { width: 100%; margin-bottom: 5mm; }
        .head td { vertical-align: bottom; padding: 0; }
        .head-logo { text-align: right; }
        table { border-collapse: collapse; }
        .meta { width: 100%; margin-bottom: 5mm; }
        .meta td { padding: 0.8mm 2mm 0.8mm 0; vertical-align: top; }
        .meta .label { color: #666; width: 24mm; }
        .intro { margin: 0 0 4mm; color: #333; }
        .items { width: 100%; }
        /* Jeder Abschnitt eine eigene Tabelle im Block: bleibt beisammen, die Überschrift nie allein am Seitenende */
        .section-block { page-break-inside: avoid; }
        .c-unit { width: 30mm; text-align: right; }
        .c-price { width: 25mm; }
        .c-factor { width: 15mm; }
        .c-amount { width: 25mm; }
        .items th { font-size: 7.5pt; font-weight: normal; color: #666; text-align: left; border-bottom: 0.8pt solid #888; padding: 1.2mm 1.5mm; }
        .items th.num, .items th.c-unit { text-align: right; }
        .items td { padding: 1.2mm 1.5mm; border-bottom: 0.4pt solid #ddd; vertical-align: top; }
        .items .section td { font-weight: bold; font-size: 8pt; text-transform: uppercase; padding-top: 4mm; border-bottom: 0.8pt solid #888; }
        .items .subtotal td { border-bottom: 0; color: #666; font-size: 7.5pt; padding-top: 0.6mm; }
        .num { text-align: right; white-space: nowrap; }
        .small { font-size: 7pt; color: #666; }
        /* rechtsbündig: 178 mm Satzbreite (A4 minus Ränder) minus 75 mm */
        .totals { width: 75mm; margin: 5mm 0 0 103mm; }
        .totals-block { page-break-inside: avoid; }
        .totals td { padding: 1.1mm 1.5mm; }
        .totals .gross td { font-weight: bold; font-size: 9.5pt; border-top: 0.8pt solid #1a1a1a; }
        .block { margin-top: 6mm; font-size: 7.8pt; color: #333; page-break-inside: avoid; }
        .block strong { display: block; margin-bottom: 1mm; color: #1a1a1a; }
        .footer { position: fixed; bottom: -16mm; left: 0; right: 0; font-size: 7pt; color: #777; }
    </style>
</head>
<body>
    <div class="footer">{{ $venue }} · erstellt mit VenueControl</div>

    {{-- Mit Logo der Halle steht ihr Name nicht noch einmal darüber --}}
    <table class="head">
        <tr>
            <td>
                @if (!$venueLogo)
                    <div class="venue">{{ $venue }}</div>
                @endif
                <h1>Costing</h1>
            </td>
            @if ($venueLogo)
                <td class="head-logo">
                    <img src="{{ $venueLogo['src'] }}" alt="{{ $venue }}" style="width: {{ $venueLogo['width'] }}mm; height: {{ $venueLogo['height'] }}mm;">
                </td>
            @endif
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="label">Veranstaltung</td>
            <td><strong>{{ $event->title }}</strong></td>
            <td class="label">Fassung</td>
            <td>{{ $costing->version }}</td>
        </tr>
        <tr>
            <td class="label">Datum</td>
            <td>{{ \App\Support\ExternSheet::date($event) }}</td>
            <td class="label">Stand</td>
            <td>{{ ($costing->sent_at ?? now())->format('d.m.Y') }}</td>
        </tr>
        <tr>
            <td class="label">Veranstalter</td>
            <td>{{ $event->promoter?->name ?? '–' }}</td>
            <td class="label">Preisliste</td>
            <td>{{ $priceList?->label() ?? '–' }}</td>
        </tr>
    </table>

    @if (filled($priceList?->intro))
        <p class="intro">{!! nl2br(e($priceList->intro), false) !!}</p>
    @endif

    <table class="items">
        <tr>
            <th>Posten</th>
            <th class="c-unit">Einheit</th>
            <th class="num c-price">Preis</th>
            <th class="num c-factor">Faktor</th>
            <th class="num c-amount">Betrag</th>
        </tr>
    </table>
    @foreach ($sections as $section => $items)
        <div class="section-block">
            <table class="items">
                <tr class="section"><td colspan="5">{{ $loop->iteration }}. {{ $section }}</td></tr>
                @php
                    $subtotal = 0.0;
                    $priced = 0;
                @endphp
                @foreach ($items as $item)
                    @php
                        $amount = $item->amount();
                        $subtotal += $amount ?? 0.0;
                        $priced += $amount === null ? 0 : 1;
                        $open += $amount === null ? 1 : 0;
                    @endphp
                    <tr>
                        <td>
                            {{ $item->name }}
                            @if (filled($item->description))
                                <br><span class="small">{{ $item->description }}</span>
                            @endif
                        </td>
                        <td class="c-unit">{{ $item->isPercent() ? 'Zuschlag' : $item->unit }}</td>
                        <td class="num c-price">
                            @if ($item->price === null)
                                –
                            @elseif ($item->isPercent())
                                {{ $number($item->price) }} %
                            @else
                                {{ $money($item->price) }}
                            @endif
                            @if ($item->minimum !== null)
                                <br><span class="small">mind. {{ $money($item->minimum) }}</span>
                            @endif
                        </td>
                        <td class="num c-factor">{{ $item->isPercent() ? 'auf ' . $money($item->factor) : $number($item->factor) }}</td>
                        <td class="num c-amount">{{ $amount === null ? 'nach Aufwand' : $money($amount) }}</td>
                    </tr>
                @endforeach
                @if ($priced > 1 && $subtotal != 0.0)
                    <tr class="subtotal"><td colspan="4" class="num">Summe {{ $section }}</td><td class="num c-amount">{{ $money($subtotal) }}</td></tr>
                @endif
            </table>
        </div>
    @endforeach

    <div class="totals-block">
        <table class="totals">
            <tr><td>Summe netto</td><td class="num">{{ $money($costing->net()) }}</td></tr>
            <tr><td>zzgl. {{ $number($costing->vat_rate) }} % MwSt.</td><td class="num">{{ $money($costing->vat()) }}</td></tr>
            <tr class="gross"><td>Gesamt brutto</td><td class="num">{{ $money($costing->gross()) }}</td></tr>
        </table>
        @if ($open > 0)
            <p class="small" style="text-align: right;">Posten „nach Aufwand“ sind in der Summe nicht enthalten.</p>
        @endif
    </div>

    @if (filled($costing->note))
        <div class="block"><strong>Anmerkung</strong>{!! nl2br(e($costing->note), false) !!}</div>
    @endif
    @if (filled($priceList?->notes))
        <div class="block"><strong>Hinweise zur Preisliste</strong>{!! nl2br(e($priceList->notes), false) !!}</div>
    @endif
</body>
</html>
