<?php

namespace App\Filament\Resources\Events\Pages\Concerns;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\ExternEvents\ExternEventResource;
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

    /** Änderungsprotokoll dieses Events, für alle mit Recht „Audit“ */
    protected function historyAction(): Action
    {
        return Action::make('history')
            ->label('Verlauf')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->visible(fn (): bool => AuditLogResource::canViewAny())
            ->url(fn (): string => AuditLogResource::getUrl('index', ['filters' => ['event' => ['value' => $this->getRecord()->getKey()]]]));
    }

    /** So sehen beteiligte Freelancer und Gewerke dieses Event (Extern-Bereich, Daysheet). */
    protected function externViewAction(): Action
    {
        return Action::make('externView')
            ->label('Ansicht Extern')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->url(fn (): string => ExternEventResource::getUrl('view', ['record' => $this->getRecord()]), shouldOpenInNewTab: true);
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
