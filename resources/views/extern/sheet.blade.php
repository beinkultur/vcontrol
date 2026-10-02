{{--
    Was Externe von einem Event sehen (App\Support\ExternSheet): im Extern-Bereich
    und im Daysheet. Stile: public/css/sheet.css. $showHead: Titelzeile zeigen
    (im Panel steht sie schon im Seitenkopf).
--}}
@php
    $showHead ??= true;
@endphp
<div class="vc-sheet">
    @if ($showHead)
        <header class="vc-sheet__head">
            <h1>{{ $sheet['title'] }}</h1>
            <p>
                {{ $sheet['date'] }} · {{ $sheet['venue'] }}
                @if (filled($sheet['promoter']))
                    · Veranstalter: {{ $sheet['promoter'] }}
                @endif
            </p>
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
        <h2>Bühne</h2>
        @if ($sheet['stage'] === null)
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
            {{-- Vom Server erzeugt, alle Texte darin escaped (App\Support\StagePlan) --}}
            <div class="vc-sheet__plan">{!! $sheet['stage']['svg'] !!}</div>
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
</div>
