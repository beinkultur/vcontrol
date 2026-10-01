{{-- Kopf des Event-Workspace in einer Zeile (HasEventHeading); Stil in public/css/vcontrol.css --}}
<header class="fi-header vc-event-header">
    <h1 class="fi-header-heading vc-event-header__title">{{ $heading }}</h1>
    <div class="vc-event-header__side">
        <span class="vc-event-header__meta">{{ $meta }}</span>
        @if ($actions)
            <x-filament::actions :actions="$actions" />
        @endif
    </div>
</header>
