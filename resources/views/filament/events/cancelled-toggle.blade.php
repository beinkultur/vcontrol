{{--
    Event-Liste: Schalter „Abgesagte Events anzeigen“ in der Kopfzeile, links
    neben den Aktionen (Render-Hook im AppPanelProvider, ListEvents::toggleCancelled).
    Aussehen wie Filaments Schalter (fi-toggle), Zustand kommt vom Server.
--}}
@php
    $classes = \Filament\Support\get_component_color_classes(\Filament\Support\View\Components\ToggleComponent::class, $on ? 'primary' : 'gray');
@endphp
<label class="vc-header-toggle">
    <button
        type="button"
        role="switch"
        aria-checked="{{ $on ? 'true' : 'false' }}"
        wire:click="toggleCancelled"
        wire:loading.attr="disabled"
        wire:target="toggleCancelled"
        @class(['fi-toggle', $on ? 'fi-toggle-on' : 'fi-toggle-off', ...$classes])
    >
        <div>
            <div aria-hidden="true"></div>
            <div aria-hidden="true"></div>
        </div>
    </button>
    <span>Abgesagte Events anzeigen</span>
</label>
