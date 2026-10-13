<?php

namespace App\Filament\Resources\PriceLists\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\PriceLists\PriceListResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** Für die Buchhaltung und Eventmanager: die Preisliste nur lesend. */
class ViewPriceList extends ViewRecord
{
    use BoxedPage;

    protected static string $resource = PriceListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
