<?php

namespace App\Filament\Resources\Costings\Pages;

use App\Filament\Resources\Costings\CostingResource;
use Filament\Resources\Pages\ListRecords;

/** Alle Costings, neueste zuerst. Angelegt wird ein Costing im Event (Buchung › Costing). */
class ListCostings extends ListRecords
{
    protected static string $resource = CostingResource::class;

    /** Die Liste nur mit dem Menüpunkt (Rollen unter Verwaltung › Venue) – einzelne Costings sehen auch die Rollen fürs Event */
    protected function authorizeAccess(): void
    {
        abort_unless(CostingResource::canViewAny(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Ein Costing entsteht im Event unter Buchung › Costing, aus der Preisliste des Events.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
