<?php

namespace App\Filament\Resources\PriceLists\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\PriceLists\PriceListResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePriceList extends CreateRecord
{
    use BoxedPage;

    protected static string $resource = PriceListResource::class;
}
