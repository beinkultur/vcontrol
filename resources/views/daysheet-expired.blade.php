<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Daysheet nicht mehr verfügbar</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f3f4f6; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: #111827; }
        .box { max-width: 28rem; margin: 1rem; padding: 1.5rem 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { margin: 0 0 .5rem; font-size: 1.2rem; }
        p { margin: 0; color: #4b5563; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Daysheet nicht mehr verfügbar</h1>
        <p>
            {{ $revoked ? 'Dieser Link wurde gesperrt.' : 'Dieser Link ist abgelaufen.' }}
            Ein aktuelles Daysheet gibt es bei der {{ $venue }}.
        </p>
    </div>
</body>
</html>
