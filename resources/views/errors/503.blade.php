{{--
    503 – Wartungsmodus (php artisan down). Beim Einspielen mit
    `php artisan down --render="errors::503" --retry=60` vorab gerendert: Die Seite
    kommt dann sogar, wenn die App gerade nicht startet (etwa während Composer
    läuft). Deshalb alles inline, nur Logo und Favicon als feste Dateien aus
    public/. Name und Farbe der Halle nur, wenn die Datenbank antwortet.
--}}
@php
    try {
        $venue = \App\Models\Setting::lookup(\App\Models\Setting::VENUE_NAME);
        $accent = \App\Enums\ColorScheme::current()->swatches()[0] ?? null;
    } catch (\Throwable) {
        $venue = null;
        $accent = null;
    }
    // Farben des Schemas: Hex (Lila-Grün) oder oklch() (Filament-Paletten)
    $accent = is_string($accent) && preg_match('/^(#[0-9a-fA-F]{6}|oklch\([0-9. %]+\))$/', $accent) ? $accent : '#2563eb';
@endphp
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta http-equiv="refresh" content="30">
    <title>Wartungsarbeiten · VenueControl</title>
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/images/favicon.svg" type="image/svg+xml">
    <style>
        :root {
            --accent: {{ $accent }};
            --bg: #f4f5f5;
            --card: #ffffff;
            --fg: #18181b;
            --muted: #5f6468;
            --line: #e4e4e7;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0d1110;
                --card: #161c1a;
                --fg: #f2f4f3;
                --muted: #a3aaa7;
                --line: #2a322f;
            }
            .logo { filter: invert(1); }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 16px;
            background: var(--bg);
            color: var(--fg);
            font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        main {
            width: 100%;
            max-width: 30rem;
            padding: 40px 28px 32px;
            background: var(--card);
            border: 1px solid var(--line);
            border-top: 4px solid var(--accent);
            border-radius: 14px;
            text-align: center;
        }
        .logo { width: 56px; height: 56px; }
        .venue { margin: 14px 0 0; font-size: 0.8rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted); }
        h1 { margin: 6px 0 10px; font-size: 1.5rem; line-height: 1.25; }
        p { margin: 0 0 10px; color: var(--muted); }
        .status { display: inline-flex; align-items: center; gap: 8px; margin-top: 10px; font-size: 0.875rem; color: var(--muted); }
        .status::before {
            content: "";
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--accent);
            animation: pulse 1.6s ease-in-out infinite;
        }
        @keyframes pulse { 50% { opacity: 0.25; } }
        @media (prefers-reduced-motion: reduce) { .status::before { animation: none; } }
    </style>
</head>
<body>
    <main>
        <img class="logo" src="/images/logo.svg" alt="VenueControl" width="56" height="56">
        @if (filled($venue))
            <p class="venue">{{ $venue }}</p>
        @endif
        <h1>Wartungsarbeiten</h1>
        <p>VenueControl wird gerade aktualisiert und ist in wenigen Minuten wieder da. Was du vorher gespeichert hast, bleibt erhalten.</p>
        <p class="status">Die Seite lädt sich alle 30 Sekunden neu.</p>
    </main>
</body>
</html>
