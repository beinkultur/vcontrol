{{--
    Was Externe von einem Event sehen (App\Support\ExternSheet): im Extern-Bereich
    und im Daysheet. Stile: public/css/sheet.css. $showHead: Titelzeile zeigen
    (im Panel steht sie schon im Seitenkopf). $logo: Logo der Venue rechts
    daneben (VenueLogo::fitted, in Pixeln), nur auf der Daysheet-Seite.
--}}
@php
    $showHead ??= true;
    $logo ??= null;
@endphp
<div class="vc-sheet">
    @if ($showHead)
        <header class="vc-sheet__head">
            <div>
                <h1>{{ $sheet['title'] }}</h1>
                <p>
                    {{ $sheet['date'] }} · {{ $sheet['venue'] }}
                    @if (filled($sheet['promoter']))
                        · Veranstalter: {{ $sheet['promoter'] }}
                    @endif
                </p>
            </div>
            @if ($logo)
                <img class="vc-sheet__logo" src="{{ $logo['src'] }}" width="{{ (int) round($logo['width']) }}" height="{{ (int) round($logo['height']) }}" alt="{{ $sheet['venue'] }}">
            @endif
        </header>
    @endif

    @if ($sheet['cancelled'])
        <p class="vc-sheet__alert">Diese Veranstaltung ist abgesagt.</p>
    @endif

    <div class="vc-sheet__grid">
        <section>
            <h2>Zeiten</h2>
            @if ($sheet['times'] === [])
                <p class="vc-sheet__empty">Noch keine Zeiten eingetragen.</p>
            @else
                <dl>
                    @foreach ($sheet['times'] as $row)
                        <dt>{{ $row['label'] }}</dt>
                        <dd>{{ $row['value'] }} Uhr</dd>
                    @endforeach
                </dl>
            @endif
        </section>

        <section>
            <h2>Ansprechpartner</h2>
            @if ($sheet['contacts'] === [])
                <p class="vc-sheet__empty">Noch niemand eingetragen.</p>
            @else
                <dl>
                    @foreach ($sheet['contacts'] as $row)
                        <dt>{{ $row['label'] }}</dt>
                        <dd>
                            {{ $row['name'] }}
                            @if ($row['time'])
                                <span class="vc-sheet__muted">· {{ $row['time'] }}</span>
                            @endif
                            @if ($row['phone'] || $row['email'])
                                <span class="vc-sheet__contact">
                                    @if ($row['phone'])
                                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $row['phone']) }}">{{ $row['phone'] }}</a>
                                    @endif
                                    @if ($row['email'])
                                        <a href="mailto:{{ $row['email'] }}">{{ $row['email'] }}</a>
                                    @endif
                                </span>
                            @endif
                        </dd>
                    @endforeach
                </dl>
            @endif
        </section>
    </div>

    <section>
        <h2>Gewerke</h2>
        @if ($sheet['services'] === [])
            <p class="vc-sheet__empty">Noch keine Gewerke eingetragen.</p>
        @else
            <div class="vc-sheet__table-wrap">
                <table>
                    <thead>
                        <tr><th>Leistung</th><th>Zuständig</th><th>Gewerk / Anbieter</th><th>Hinweis</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($sheet['services'] as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td>{{ $row['responsible'] ?? '–' }}</td>
                                <td>{{ $row['provider'] ?? '–' }}</td>
                                <td>{{ $row['note'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section>
        <h2>Dateien</h2>
        @if ($sheet['files'] === [])
            <p class="vc-sheet__empty">Keine Dateien.</p>
        @else
            @foreach ($sheet['files'] as $tag => $files)
                <h3>{{ $tag }}</h3>
                <ul class="vc-sheet__files">
                    @foreach ($files as $file)
                        <li>
                            <a href="{{ $file['url'] }}" target="_blank" rel="noopener">{{ $file['name'] }}</a>
                            <span class="vc-sheet__muted">· {{ $file['stand'] }} · {{ $file['size'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        @endif
    </section>

    <section>
        <h2>Notizen</h2>
        @if ($sheet['notes'] === [])
            <p class="vc-sheet__empty">Keine Notizen.</p>
        @else
            @foreach ($sheet['notes'] as $note)
                <div class="vc-sheet__note">
                    <strong>{{ $note['subject'] }}</strong>
                    <span class="vc-sheet__muted">· {{ $note['date'] }}@if ($note['author']) · {{ $note['author'] }}@endif</span>
                    <p>{{ $note['body'] }}</p>
                </div>
            @endforeach
        @endif
    </section>

    {{-- Nur mit dem Modul Saalplan --}}
    @if ($sheet['seating_plan'] !== null)
        @php
            $seatingPlan = $sheet['seating_plan'];
        @endphp
        <section>
            <h2>Saalplan</h2>
            @if ($seatingPlan['file'] === null)
                <p class="vc-sheet__empty">Kein Saalplan.</p>
            @else
                <p class="vc-sheet__seating-plan">
                    <a href="{{ $seatingPlan['file']['url'] }}" target="_blank" rel="noopener">{{ $seatingPlan['file']['name'] }}</a>
                    <span class="vc-sheet__muted">· PDF</span>
                </p>
            @endif
            @if ($seatingPlan['areas'] !== [] || $seatingPlan['seating'] !== [])
                <dl>
                    @if ($seatingPlan['areas'] !== [])
                        <dt>Bereiche</dt>
                        <dd>{{ implode(', ', $seatingPlan['areas']) }}</dd>
                    @endif
                    @if ($seatingPlan['seating'] !== [])
                        <dt>Bestuhlung</dt>
                        <dd>{{ implode(', ', $seatingPlan['seating']) }}</dd>
                    @endif
                </dl>
            @endif
        </section>
    @endif

    {{-- Nur mit dem Modul Preislisten & Costing und wenn die Venue es freigibt (Verwaltung › Venue) --}}
    @if ($sheet['costing'] !== null)
        <section>
            <h2>Costing</h2>
            <p class="vc-sheet__seating-plan">
                <a href="{{ $sheet['costing']['url'] }}" target="_blank" rel="noopener">{{ $sheet['costing']['name'] }}</a>
                <span class="vc-sheet__muted">· PDF</span>
            </p>
        </section>
    @endif

    {{-- Nur mit dem Modul Bühnenplan --}}
    @if ($sheet['stage'] !== null)
        <section>
            <h2>Bühne</h2>
            @if ($sheet['stage']['rows'] === [])
                <p class="vc-sheet__empty">Noch keine Bühne eingetragen.</p>
            @else
                <dl>
                    @foreach ($sheet['stage']['rows'] as $label => $value)
                        <dt>{{ $label }}</dt>
                        <dd>{{ $value }}</dd>
                    @endforeach
                </dl>
                @if ($sheet['stage']['notes'])
                    <p class="vc-sheet__stage-notes">{{ $sheet['stage']['notes'] }}</p>
                @endif
                {{-- Eingeklappt, erst auf Klick; zum Drucken klappt er von selbst auf (Skript unten) --}}
                <details class="vc-sheet__plan-toggle">
                    <summary>
                        <span class="vc-sheet__when-closed">Bühnenplan anzeigen</span>
                        <span class="vc-sheet__when-open">Bühnenplan ausblenden</span>
                    </summary>
                    {{-- Vom Server erzeugt, alle Texte darin escaped (App\Support\StagePlan) --}}
                    <div class="vc-sheet__plan">{!! $sheet['stage']['svg'] !!}</div>
                </details>
            @endif
        </section>

        {{-- Gedruckt (Daysheet, PDF) gehört der Bühnenplan dazu --}}
        <script>
            window.addEventListener('beforeprint', () => document.querySelectorAll('.vc-sheet__plan-toggle').forEach((plan) => { plan.open = true; }));
        </script>
    @endif
</div>
