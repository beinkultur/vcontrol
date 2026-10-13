<?php

namespace App\Filament\Resources\PriceLists\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\PriceLists\PriceListResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/** Gelöscht wird nur eine Preisliste, die kein Event und kein Costing nutzt (PriceListPolicy). */
class EditPriceList extends EditRecord
{
    use BoxedPage;

    protected static string $resource = PriceListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PriceListResource::copyAction(),
            DeleteAction::make(),
        ];
    }
}
