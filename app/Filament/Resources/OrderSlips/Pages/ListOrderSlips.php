<?php

namespace App\Filament\Resources\OrderSlips\Pages;

use App\Filament\Resources\OrderSlips\OrderSlipResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderSlips extends ListRecords
{
    protected static string $resource = OrderSlipResource::class;
}
