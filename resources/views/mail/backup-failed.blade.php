Die nächtliche Sicherung von {{ $host }} ist fehlgeschlagen.

@foreach ($runs as $run)
{{ $run->kindLabel() }}, {{ $run->started_at?->format('d.m.Y H:i') }} Uhr:
{!! $run->error !!}

@endforeach
Stand und „Jetzt sichern“: {{ \App\Filament\Pages\ManageSystem::getUrl() }}
