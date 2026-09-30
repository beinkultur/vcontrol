<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Neu anlegen mit den Daten der Buchung; laufende Nummer und VA-ID vergibt
 * Event::booted. Danach geht es direkt in den Workspace.
 */
class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    protected function getRedirectUrl(): string
    {
        return EventResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
