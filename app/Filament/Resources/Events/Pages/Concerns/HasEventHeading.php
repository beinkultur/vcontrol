<?php

namespace App\Filament\Resources\Events\Pages\Concerns;

use App\Filament\Resources\Events\EventResource;
use App\Support\EventDisplay;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/** Kopf des Workspace wie in der PHP-Version: Titel, darunter Datum · Veranstalter · VA-ID · Status. */
trait HasEventHeading
{
    public function getHeading(): string
    {
        return (string) $this->getRecord()->getAttribute('title');
    }

    public function getSubheading(): ?string
    {
        return EventDisplay::meta($this->getRecord());
    }

    protected function stagePlanAction(): Action
    {
        return Action::make('stagePlan')
            ->label('Bühnenplan')
            ->icon(Heroicon::OutlinedMap)
            ->color('gray')
            ->url(fn (): string => EventResource::getUrl('stage-plan', ['record' => $this->getRecord()]));
    }
}
