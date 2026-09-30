<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gästeliste – {{ $event->title }}</title>
    <style>
        body { font: 14px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif; color: #111; margin: 0; background: #f3f4f6; }
        .toolbar { display: flex; gap: .75rem; justify-content: flex-end; padding: 1rem; }
        .toolbar button, .toolbar a { font: inherit; padding: .5rem 1rem; border-radius: .5rem; border: 1px solid #d1d5db; background: #fff; color: #111; text-decoration: none; cursor: pointer; }
        .toolbar button { background: #2563eb; border-color: #2563eb; color: #fff; }
        .sheet { background: #fff; max-width: 190mm; margin: 0 auto 2rem; padding: 12mm; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        h1 { font-size: 1.4rem; margin: 0; }
        .meta { color: #4b5563; margin: .25rem 0 1rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: .35rem .5rem; border-bottom: 1px solid #e5e7eb; }
        th { border-bottom: 2px solid #111; }
        .nr, .count { width: 3.5rem; text-align: right; }
        .check { width: 2.5rem; }
        tfoot td { border-top: 2px solid #111; border-bottom: 0; font-weight: 600; }
        footer { display: flex; justify-content: space-between; color: #6b7280; font-size: .8rem; margin-top: 1.5rem; }
        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="{{ \App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $event]) }}">← Zurück</a>
        <button type="button" onclick="window.print()">Drucken / PDF speichern</button>
    </div>
    <article class="sheet">
        <h1>{{ $event->title }}</h1>
        <p class="meta">
            Gästeliste · {{ $event->starts_at?->format('d.m.Y') ?? '–' }}
            @if ($event->promoter) · {{ $event->promoter->name }} @endif
            @if ($event->va_id) · VA-ID {{ $event->va_id }} @endif
        </p>

        @if ($guests->isEmpty())
            <p>Keine Gäste eingetragen.</p>
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
                            <td class="check">☐</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3">Gesamt Tickets</td>
                        <td class="count">{{ $ticketTotal }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        @endif

        <footer>
            <span>{{ $venueName ? $venueName . ' · ' : '' }}VenueControl</span>
            <span>Erstellt: {{ now()->format('d.m.Y H:i') }}</span>
        </footer>
    </article>
</body>
</html>
