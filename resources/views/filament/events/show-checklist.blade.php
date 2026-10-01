{{-- Prüfpunkte einer Durchführungs-Checkliste (App\Support\ShowChecklist); Stile: public/css/vcontrol.css --}}
<div class="vc-checks">
    @foreach (\App\Support\ShowChecklist::GROUPS as $group => $items)
        <div class="vc-checks__group">
            <h4 class="vc-checks__title">{{ $group }}</h4>
            <ul class="vc-checks__list">
                @foreach ($items as $key => [$name, $question])
                    @php
                        $value = $checks[$key]['value'] ?? null;
                        $note = $checks[$key]['note'] ?? null;
                    @endphp
                    <li class="vc-checks__item">
                        <span @class(['vc-checks__mark', 'vc-checks__mark--yes' => $value === 'yes', 'vc-checks__mark--no' => $value === 'no'])>
                            {{ match ($value) { 'yes' => 'Ja', 'no' => 'Nein', default => '–' } }}
                        </span>
                        <span class="vc-checks__name">{{ $name }}@if ($question) <span class="vc-muted">{{ $question }}</span>@endif</span>
                        @if (filled($note))
                            <span class="vc-checks__note">{{ $note }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</div>
