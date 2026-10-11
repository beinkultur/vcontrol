{{-- Letzte Sicherungsläufe (App\Support\Backups) auf der Seite System; Stile: public/css/vcontrol.css --}}
@if ($runs->isEmpty())
    <p class="vc-muted">Noch keine Sicherung gelaufen.</p>
@else
    <div class="vc-audit-changes">
        <table>
            <thead>
                <tr>
                    <th>Zeitpunkt</th>
                    <th>Was</th>
                    <th>Ergebnis</th>
                    <th>Auslöser</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($runs as $run)
                    <tr>
                        <td>{{ $run->started_at?->format('d.m.Y H:i') }}</td>
                        <td>{{ $run->kindLabel() }}</td>
                        <td>
                            @if ($run->status === \App\Models\BackupRun::OK)
                                <span class="vc-ok">✓</span> {{ $run->file_name }} ({{ $run->sizeLabel() }})
                            @elseif ($run->status === \App\Models\BackupRun::FAILED)
                                <span class="vc-backup-failed">Fehler:</span> {{ $run->error }}
                            @else
                                läuft …
                            @endif
                        </td>
                        <td>{{ ['zeitplan' => 'Cronjob', 'manuell' => 'von Hand', 'konsole' => 'Konsole'][$run->trigger] ?? $run->trigger }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
