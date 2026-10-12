<?php

namespace App\Filament\Resources\SeatingPlans\Pages;

use App\Filament\Resources\SeatingPlans\SeatingPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSeatingPlans extends ManageRecords
{
    protected static string $resource = SeatingPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
