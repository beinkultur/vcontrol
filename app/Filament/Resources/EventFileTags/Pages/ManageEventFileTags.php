<?php

namespace App\Filament\Resources\EventFileTags\Pages;

use App\Filament\Resources\EventFileTags\EventFileTagResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEventFileTags extends ManageRecords
{
    protected static string $resource = EventFileTagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
