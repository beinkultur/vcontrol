{{-- Advancing-Link gesperrt, abgelaufen oder abgeschlossen (AdvancingController, 410) --}}
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Fragenkatalog nicht mehr verfügbar</title>
    @include('partials.icons')
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f3f4f6; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: #111827; }
        .box { max-width: 28rem; margin: 1rem; padding: 1.5rem 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { margin: 0 0 .5rem; font-size: 1.2rem; }
        p { margin: 0; color: #4b5563; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Fragenkatalog nicht mehr verfügbar</h1>
        <p>
            @if ($reason === 'revoked')
                Dieser Link wurde gesperrt.
            @elseif ($reason === 'expired')
                Dieser Link ist abgelaufen.
            @else
                Der Fragenkatalog ist abgeschlossen, Änderungen sind nicht mehr möglich.
            @endif
            Bei Fragen meldet euch bitte bei der {{ $venue }}.
        </p>
    </div>
</body>
</html>
