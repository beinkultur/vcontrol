<?php

namespace App\Filament\Resources\Costings\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Costings\CostingResource;
use App\Filament\Support\CostingActions;
use App\Models\Costing;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Entwurf bearbeiten: Faktoren anpassen, Posten ergänzen, Anmerkung. Eine
 * verschickte Fassung lässt sich nicht mehr bearbeiten (CostingPolicy) – wer sie
 * aufruft, landet in der Ansicht, dort gibt es „Neue Fassung“.
 */
class EditCosting extends EditRecord
{
    use BoxedPage;

    protected static string $resource = CostingResource::class;

    /** Ziel nach dem Löschen, vor dem Löschen bestimmt */
    protected ?string $afterDeleteUrl = null;

    public function mount(int|string $record): void
    {
        $costing = $this->resolveRecord($record);
        if (!CostingResource::canEdit($costing) && CostingResource::canView($costing)) {
            $this->record = $costing;
            $this->redirect(CostingResource::getUrl('view', ['record' => $costing]));

            return;
        }

        parent::mount($record);
    }

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

        return $costing->label() . ' · Entwurf';
    }

    protected function getHeaderActions(): array
    {
        return [
            CostingActions::pdf(),
            CostingActions::send(),
            // Nach dem Löschen gibt es den Entwurf nicht mehr – das Ziel (Costing-Reiter
            // des Events) steht deshalb schon vorher fest (Fehler 500 bis 22.10.2026)
            DeleteAction::make()
                ->before(function (): void {
                    $this->afterDeleteUrl = CostingResource::eventUrl($this->getRecord());
                })
                ->successRedirectUrl(fn (): ?string => $this->afterDeleteUrl),
        ];
    }
}
