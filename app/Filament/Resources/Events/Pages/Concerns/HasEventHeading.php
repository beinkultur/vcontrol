<?php

namespace App\Filament\Resources\Events\Pages\Concerns;

use App\Filament\Resources\Events\EventResource;
use App\Support\EventDisplay;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * Kompakter Kopf des Workspace in einer Zeile: links der Titel, rechts
 * Datum · Veranstalter · VA-ID · Status und die Aktionen (Bühnenplan …).
 */
trait HasEventHeading
{
    public function getHeading(): string
    {
        return (string) $this->getRecord()->getAttribute('title');
    }

    public function getHeader(): ?View
    {
        return view('filament.events.header', [
            'heading' => $this->getHeading(),
            'meta' => EventDisplay::meta($this->getRecord()),
            'actions' => $this->getCachedHeaderActions(),
        ]);
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
