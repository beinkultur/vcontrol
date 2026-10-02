{{-- Daysheet-Seite (DaysheetController): ohne Konto über den Link, zum Drucken oder als PDF. Inhalt: extern/sheet --}}
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $documentTitle }}</title>
    <link rel="stylesheet" href="{{ asset('css/sheet.css') }}?v={{ @filemtime(public_path('css/sheet.css')) ?: 0 }}">
    <style>
        body { margin: 0; background: #f3f4f6; font: 15px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; color: #111827; }
        .toolbar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between; max-width: 60rem; margin: 0 auto; padding: 1rem; }
        .toolbar span { color: #4b5563; }
        .toolbar strong { color: #b45309; }
        .toolbar button { font: inherit; padding: .5rem 1rem; border-radius: .5rem; border: 1px solid #2563eb; background: #2563eb; color: #fff; cursor: pointer; }
        .page { max-width: 60rem; margin: 0 auto 2rem; padding: 0 1rem; }
        .footer { max-width: 60rem; margin: 0 auto 2rem; padding: 0 1rem; color: #6b7280; font-size: .8rem; }
        @media print {
            @page { size: A4; margin: 12mm; }
            body { background: #fff; }
            .toolbar, .footer { display: none; }
            .page { max-width: none; margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        @if ($preview)
            <span><strong>Vorschau</strong> – so sehen die Empfänger das Daysheet.</span>
        @else
            <span>Daysheet der {{ $venue }} · gültig bis {{ $validUntil->format('d.m.Y, H:i') }} Uhr</span>
        @endif
        <button type="button" onclick="window.print()">Drucken / als PDF speichern</button>
    </div>
    <main class="page">
        @include('extern.sheet', ['sheet' => $sheet, 'showHead' => true])
    </main>
    <p class="footer">{{ $venue }} · erstellt mit VenueControl</p>
</body>
</html>
