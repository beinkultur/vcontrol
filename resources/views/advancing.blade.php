{{--
    Fragenkatalog für den Veranstalter (AdvancingController): ohne Konto über den
    Link, klassisches Formular (POST mit CSRF), kein Livewire. Die Seite bekommt
    nur aufbereitete Abschnitte, Titel und Datum des Events – nie das Event selbst.
    Je Feld steht in o[key] der Wert beim Laden: Übernommen wird nur, was sich
    dagegen geändert hat (Advancings::save).
--}}
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $documentTitle }}</title>
    @include('partials.icons')
    <style>{!! \App\Enums\ColorScheme::current()->css() !!}</style>
    <style>
        :root { --accent: var(--vc-accent, #2563eb); --border: #e5e7eb; --muted: #6b7280; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; font: 16px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; color: #111827; }
        .page { max-width: 46rem; margin: 0 auto; padding: 1rem 1rem 6rem; }
        .head { display: flex; align-items: flex-end; justify-content: space-between; gap: .75rem 1.5rem; flex-wrap: wrap; margin: .5rem 0 1rem; }
        .head h1 { margin: 0; font-size: 1.45rem; line-height: 1.25; }
        .head p { margin: .2rem 0 0; color: var(--muted); }
        .head img { max-width: 40%; height: auto; }
        .intro { margin: 0 0 1rem; }
        .notice { margin: 0 0 1rem; padding: .7rem 1rem; border-radius: .5rem; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .notice--warn { background: #fffbeb; color: #92400e; border-color: #fde68a; }
        .notice--error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .card { background: #fff; border: 1px solid var(--border); border-radius: .75rem; padding: 1rem 1.1rem; margin: 0 0 1rem; }
        .card h2 { margin: 0 0 .35rem; font-size: 1.1rem; color: var(--accent); }
        .card > .text { margin: 0 0 .75rem; color: #374151; font-size: .92rem; }
        .info { margin: .75rem 0; padding: .6rem .8rem; border-left: 3px solid var(--accent); background: #f9fafb; font-size: .92rem; }
        .info strong { display: block; margin-bottom: .2rem; }
        .field { margin: .85rem 0 0; }
        .field:first-of-type { margin-top: 0; }
        .field label, .field .label { display: block; font-weight: 600; margin-bottom: .25rem; }
        .field .help { display: block; color: var(--muted); font-size: .85rem; margin: -.15rem 0 .3rem; }
        .field .value { padding: .45rem 0; white-space: pre-line; }
        .req { color: #b91c1c; }
        input[type=text], input[type=date], select, textarea {
            width: 100%; font: inherit; padding: .55rem .7rem; border: 1px solid #d1d5db; border-radius: .5rem; background: #fff; color: inherit;
        }
        input:focus, select:focus, textarea:focus { outline: 2px solid var(--accent); outline-offset: 0; border-color: var(--accent); }
        textarea { min-height: 6rem; resize: vertical; }
        .error { color: #b91c1c; font-size: .85rem; margin-top: .25rem; }
        .field--error input, .field--error select, .field--error textarea { border-color: #dc2626; }
        .actions { position: sticky; bottom: 0; display: flex; gap: .75rem; justify-content: flex-end; padding: .75rem 1rem; margin: 0 -1rem; background: rgba(243, 244, 246, .96); border-top: 1px solid var(--border); }
        .actions button { font: inherit; font-weight: 600; padding: .6rem 1.1rem; border-radius: .5rem; border: 1px solid var(--accent); cursor: pointer; }
        .actions .secondary { background: #fff; color: var(--accent); }
        .actions .primary { background: var(--accent); color: #fff; }
        .footer { color: var(--muted); font-size: .8rem; text-align: center; margin-top: 1.5rem; }
        .footer p { margin: 0 0 .5rem; }
        .footer a { color: inherit; }
        @media (max-width: 640px) {
            .head img { max-width: 60%; order: -1; }
            .actions button { flex: 1; }
        }
    </style>
</head>
<body>
<main class="page">
    <header class="head">
        <div>
            <h1>{{ $eventTitle }}</h1>
            <p>{{ $eventDate }} · {{ $venue }} · Advancing „{{ $templateName }}“</p>
        </div>
        @if ($logo)
            <img src="{{ $logo['src'] }}" width="{{ $logo['width'] }}" height="{{ $logo['height'] }}" alt="{{ $venue }}">
        @endif
    </header>

    @if (session('status'))
        <p class="notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="notice notice--error" role="alert">Bitte prüft die markierten Felder – gespeichert wurde noch nichts.</p>
    @endif
    @if ($closed)
        <p class="notice notice--warn">Der Fragenkatalog ist abgeschlossen. Ihr seht hier eure Angaben, ändern lässt sich nichts mehr.</p>
    @elseif ($submittedAt && !session('status'))
        <p class="notice">Zuletzt abgeschickt am {{ $submittedAt->format('d.m.Y, H:i') }} Uhr. Ihr könnt weiter ändern und erneut abschicken.</p>
    @endif

    @if ($intro !== '')
        <p class="intro">{!! nl2br(e($intro), false) !!}</p>
    @endif

    <form method="post" action="{{ route('advancing.update', $token) }}" novalidate>
        @csrf
        @foreach ($sections as $section)
            <section class="card">
                @if ($section['title'] !== '')
                    <h2>{{ $section['title'] }}</h2>
                @endif
                @if ($section['text'] !== '')
                    <p class="text">{!! nl2br(e($section['text']), false) !!}</p>
                @endif
                @foreach ($section['items'] as $item)
                    @if ($item['type'] === 'info')
                        <div class="info">
                            @if ($item['label'] !== '')
                                <strong>{{ $item['label'] }}</strong>
                            @endif
                            {!! nl2br(e($item['text']), false) !!}
                        </div>
                    @elseif (!$item['editable'])
                        <div class="field">
                            <span class="label">{{ $item['label'] }}</span>
                            <div class="value">{{ $item['display'] }}</div>
                        </div>
                    @else
                        @php
                            $name = 'a[' . $item['key'] . ']';
                            $id = 'f-' . $item['key'];
                            $current = (string) old('a.' . $item['key'], $item['value']);
                            $hasError = $errors->has('a.' . $item['key']);
                        @endphp
                        <div class="field {{ $hasError ? 'field--error' : '' }}">
                            <label for="{{ $id }}">{{ $item['label'] }}@if ($item['required']) <span class="req">*</span>@endif</label>
                            @if ($item['help'] !== '')
                                <span class="help">{{ $item['help'] }}</span>
                            @endif
                            @if ($item['type'] === 'textarea')
                                <textarea id="{{ $id }}" name="{{ $name }}" maxlength="5000">{{ $current }}</textarea>
                            @elseif ($item['type'] === 'number')
                                <input type="text" id="{{ $id }}" name="{{ $name }}" value="{{ $current }}" inputmode="numeric" maxlength="4" autocomplete="off">
                            @elseif ($item['type'] === 'date')
                                <input type="date" id="{{ $id }}" name="{{ $name }}" value="{{ $current }}">
                            @elseif ($item['type'] === 'yes_no' || $item['type'] === 'choice')
                                <select id="{{ $id }}" name="{{ $name }}">
                                    <option value="">–</option>
                                    @foreach ($item['type'] === 'yes_no' ? ['ja', 'nein'] : $item['options'] as $option)
                                        <option value="{{ $option }}" @selected($current === $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="text" id="{{ $id }}" name="{{ $name }}" value="{{ $current }}" maxlength="500">
                            @endif
                            <input type="hidden" name="o[{{ $item['key'] }}]" value="{{ old('o.' . $item['key'], $item['value']) }}">
                            @error('a.' . $item['key'])
                                <div class="error">{{ $message }}</div>
                            @enderror
                        </div>
                    @endif
                @endforeach
            </section>
        @endforeach

        @unless ($closed)
            <div class="actions">
                <button type="submit" name="aktion" value="speichern" class="secondary">Zwischenspeichern</button>
                <button type="submit" name="aktion" value="absenden" class="primary">Absenden</button>
            </div>
        @endunless
    </form>

    <footer class="footer">
        <p>{{ $venue }} · Link gültig bis {{ $validUntil->format('d.m.Y, H:i') }} Uhr · erstellt mit <a href="{{ config('venuecontrol.website') }}" target="_blank" rel="noopener">VenueControl</a></p>
    </footer>
</main>
</body>
</html>
