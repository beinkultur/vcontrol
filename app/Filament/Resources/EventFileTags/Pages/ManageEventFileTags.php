<?php

namespace App\Filament\Resources\EventFileTags\Pages;

use App\Filament\Resources\EventFileTags\EventFileTagResource;
use App\Models\EventFileTag;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEventFileTags extends ManageRecords
{
    protected static string $resource = EventFileTagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data): array => $data + [
                'sort_order' => (int) EventFileTag::max('sort_order') + 10,
            ]),
        ];
    }
}
