<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $documentTitle }}</title>
    <style>
        body { margin: 0; background: #f3f4f6; font: 14px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif; color: #111; }
        .toolbar { display: flex; gap: .75rem; justify-content: flex-end; padding: 1rem; }
        .toolbar button, .toolbar a { font: inherit; padding: .5rem 1rem; border-radius: .5rem; border: 1px solid #d1d5db; background: #fff; color: #111; text-decoration: none; cursor: pointer; }
        .toolbar button { background: #2563eb; border-color: #2563eb; color: #fff; }
        .sheet { background: #fff; max-width: 277mm; margin: 0 auto 2rem; padding: 8mm; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        .sheet svg { display: block; width: 100%; height: auto; }
        @media print {
            @page { size: A4 landscape; margin: 8mm; }
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; }
            .sheet svg { max-height: 190mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="{{ \App\Filament\Resources\Events\EventResource::getUrl('stage-plan', ['record' => $event]) }}">← Bühnenplan</a>
        <button type="button" onclick="window.print()">Drucken / PDF speichern</button>
    </div>
    <div class="sheet">{!! $svg !!}</div>
</body>
</html>
