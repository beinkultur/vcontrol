{{-- Unterschrift in der Ansicht (nur geprüfte PNG-data:-URLs) --}}
<div>
    <p class="vc-label">{{ $label ?? 'Unterschrift' }}</p>
    @if (\App\Filament\Forms\SignaturePad::isValid($signature))
        <img src="{{ $signature }}" alt="Unterschrift" class="vc-signature__img">
    @else
        <p class="vc-muted">Keine Unterschrift.</p>
    @endif
</div>
