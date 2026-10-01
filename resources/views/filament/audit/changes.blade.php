{{-- Werte vorher/nachher eines Protokolleintrags (App\Support\AuditPresenter); Stile: public/css/vcontrol.css --}}
@if ($rows === [])
    <p class="vc-muted">Keine Werte.</p>
@else
    <div class="vc-audit-changes">
        <table>
            <thead>
                <tr>
                    <th>Feld</th>
                    @if ($action !== \App\Support\Audit::CREATED)<th>Vorher</th>@endif
                    @if ($action !== \App\Support\Audit::DELETED)<th>Nachher</th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="vc-audit-changes__field">{{ $row['field'] }}</td>
                        @if ($action !== \App\Support\Audit::CREATED)<td class="vc-audit-changes__old">{{ $row['old'] }}</td>@endif
                        @if ($action !== \App\Support\Audit::DELETED)<td>{{ $row['new'] }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
