{{-- Übergreifende Dateien an diesem Event (gepflegt zentral, hier nur zum Herunterladen) --}}
<section class="vc-card">
    <h3 class="vc-card__heading">Übergreifende Dateien</h3>
    <ul class="vc-files__list">
        @foreach ($files as $file)
            <li>
                <span class="vc-muted">{{ $file->tag?->name }}:</span>
                <a href="{{ $file->downloadUrl() }}" target="_blank" rel="noopener" class="vc-files__name">{{ $file->displayName() }}</a>
                <span class="vc-muted">· {{ $file->standLabel() }}</span>
            </li>
        @endforeach
    </ul>
</section>
