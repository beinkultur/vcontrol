<?php

namespace App\Filament\Resources\InventoryItems\Pages;

use App\Filament\Resources\InventoryCategories\InventoryCategoryResource;
use App\Filament\Resources\InventoryItems\InventoryItemResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInventoryItems extends ManageRecords
{
    protected static string $resource = InventoryItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('categories')
                ->label('Kategorien')
                ->color('gray')
                ->url(InventoryCategoryResource::getUrl())
                ->visible(InventoryCategoryResource::canViewAny()),
            CreateAction::make(),
        ];
    }
}
