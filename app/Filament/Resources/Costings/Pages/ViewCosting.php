<?php

namespace App\Filament\Resources\Costings\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Costings\CostingResource;
use App\Filament\Support\CostingActions;
use App\Models\Costing;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** Costing lesen: verschickte Fassungen und alles für die Buchhaltung. */
class ViewCosting extends ViewRecord
{
    use BoxedPage;

    protected static string $resource = CostingResource::class;

    public function getHeading(): string
    {
        /** @var Costing $costing */
        $costing = $this->getRecord();

        return 'Costing ' . $costing->event->title;
    }

    public function getSubheading(): string
    {
        /** @var Costing $costing */
        $costing = $this->getRecord();

        return $costing->label() . ' · ' . $costing->statusLabel();
    }

    protected function getHeaderActions(): array
    {
        return [
            CostingActions::pdf(),
            CostingActions::send(),
            CostingActions::duplicate(),
            EditAction::make(),
        ];
    }
}
