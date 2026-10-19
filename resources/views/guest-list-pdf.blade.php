{{--
    Gästeliste als PDF (App\Support\GuestLists::pdf, Dompdf), immer DIN A4 hoch:
    Event, Datum, Veranstalter, VA-ID; Gäste nach Name mit Nummer, Anzahl
    Freikarten und Spalte zum Abhaken, Summe. Die Kopfzeile der Tabelle steht auf
    jeder Seite, Seitenzahlen setzt GuestLists. Nur CSS 2.1 (Dompdf), Schrift
    DejaVu Sans.
--}}
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ \App\Support\GuestLists::fileName($event) }}</title>
    <style>
        @page { size: A4 portrait; margin: 14mm 14mm 20mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #111; line-height: 1.35; }
        .venue { font-size: 9pt; font-weight: bold; letter-spacing: 0.04em; text-transform: uppercase; color: #555; }
        h1 { font-size: 16pt; margin: 1mm 0 1.5mm; }
        .meta { color: #444; margin: 0 0 5mm; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 8pt; font-weight: bold; border-bottom: 1pt solid #111; padding: 1.5mm 2mm; }
        td { padding: 1.6mm 2mm; border-bottom: 0.4pt solid #ccc; }
        tr { page-break-inside: avoid; }
        .nr, .count { width: 14mm; text-align: right; }
        .check { width: 12mm; text-align: center; }
        tfoot td { border-top: 1pt solid #111; border-bottom: 0; font-weight: bold; }
        .empty { color: #555; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7pt; color: #777; }
    </style>
</head>
<body>
    <div class="footer">{{ $venue }} · {{ config('app.name') }} · erstellt {{ now()->format('d.m.Y H:i') }}</div>

    <div class="venue">{{ $venue }}</div>
    <h1>{{ $event->title }}</h1>
    <p class="meta">
        Gästeliste · {{ $event->starts_at ? \App\Support\EventDisplay::weekday($event->starts_at) . ', ' . $event->starts_at->format('d.m.Y') : '–' }}
        @if ($event->promoter) · {{ $event->promoter->name }} @endif
        @if ($event->va_id) · VA-ID {{ $event->va_id }} @endif
    </p>

    @if ($guests->isEmpty())
        <p class="empty">Keine Gäste eingetragen.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th class="nr">Nr.</th>
                    <th>Name</th>
                    <th>Vorname</th>
                    <th class="count">Anz.</th>
                    <th class="check">da</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($guests as $guest)
                    <tr>
                        <td class="nr">{{ $loop->iteration }}</td>
                        <td>{{ $guest->last_name }}</td>
                        <td>{{ $guest->first_name }}</td>
                        <td class="count">{{ $guest->free_tickets }}</td>
                        <td class="check">&#9744;</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3">{{ $guests->count() }} {{ $guests->count() === 1 ? 'Gast' : 'Gäste' }} · Gesamt Tickets</td>
                    <td class="count">{{ $ticketTotal }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    @endif
</body>
</html>
