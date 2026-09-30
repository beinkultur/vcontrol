<?php

namespace App\Filament\Resources\InventoryCategories\Pages;

use App\Filament\Resources\InventoryCategories\InventoryCategoryResource;
use App\Models\InventoryCategory;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInventoryCategories extends ManageRecords
{
    protected static string $resource = InventoryCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data): array => $data + [
                'sort_order' => (int) InventoryCategory::max('sort_order') + 10,
            ]),
        ];
    }
}
