<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\Concerns\HasEventHeading;
use Filament\Resources\Pages\EditRecord;

/**
 * Der Event-Workspace: Übersicht und Phasen (siehe EventForm). Events werden
 * storniert, nicht gelöscht – daher keine Lösch-Aktion.
 */
class EditEvent extends EditRecord
{
    use HasEventHeading;

    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->stagePlanAction(),
        ];
    }
}
