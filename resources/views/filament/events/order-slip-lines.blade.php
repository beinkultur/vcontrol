{{-- Positionen eines Bestellscheins in der Ansicht --}}
<table class="vc-lines">
    <thead>
        <tr>
            <th>Kategorie</th>
            <th>Artikel</th>
            <th>Einheit</th>
            <th class="vc-num">Menge</th>
            <th class="vc-num">Einzelpreis</th>
            <th class="vc-num">Summe</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($slip->items as $item)
            <tr>
                <td>{{ $item->category_name }}</td>
                <td>{{ $item->article_name }}</td>
                <td>{{ $item->unit ?? '–' }}</td>
                <td class="vc-num">{{ $item->quantityLabel() }}</td>
                <td class="vc-num">{{ \App\Models\OrderSlip::money((float) $item->unit_price) }}</td>
                <td class="vc-num">{{ \App\Models\OrderSlip::money((float) $item->line_total) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5">Gesamtsumme</td>
            <td class="vc-num">{{ \App\Models\OrderSlip::money($slip->total()) }}</td>
        </tr>
    </tfoot>
</table>
