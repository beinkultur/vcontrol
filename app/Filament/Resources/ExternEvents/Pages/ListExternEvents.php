<?php

namespace App\Filament\Resources\ExternEvents\Pages;

use App\Filament\Resources\ExternEvents\ExternEventResource;
use Filament\Resources\Pages\ListRecords;

class ListExternEvents extends ListRecords
{
    protected static string $resource = ExternEventResource::class;

    /** Die Liste nur mit „Events (extern)“, wie /extern/events der PHP-Version – intern gibt es „Events“. */
    protected function authorizeAccess(): void
    {
        abort_unless(ExternEventResource::canViewAny(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Events, an denen du beteiligt bist – Zeiten, Ansprechpartner, Gewerke, Bühne, Dateien und Notizen.';
    }
}
