{{-- Hinweis für System-Admins oben auf jeder Seite, wenn die Sicherung fehlt oder scheiterte (App\Support\Backups::warning) --}}
<div class="vc-backup-warning" role="alert">
    <strong>Sicherung:</strong> {{ $warning }}
    <a href="{{ \App\Filament\Pages\ManageSystem::getUrl() }}">Zur Seite System</a>
</div>
