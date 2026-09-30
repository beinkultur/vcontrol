<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/** Offen/Abgeschlossen/Alle steht wie in der PHP-Version im Filter „Status“, nicht in Reitern. */
class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    /** Kein Pfad über der Liste – jede Zeile zählt. */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Neues Event'),
        ];
    }
}
