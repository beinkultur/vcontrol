<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\Concerns\HasEventHeading;
use App\Filament\Support\DaysheetActions;
use Filament\Resources\Pages\EditRecord;

/**
 * Der Event-Workspace: Übersicht und Phasen (siehe EventForm). Events werden
 * storniert, nicht gelöscht – daher keine Lösch-Aktion.
 */
class EditEvent extends EditRecord
{
    use BoxedPage;
    use HasEventHeading;

    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->stagePlanAction(),
            $this->externViewAction(),
            DaysheetActions::send(),
            $this->historyAction(),
        ];
    }

    /**
     * Wer das Event nur lesen darf, landet beim Aufruf in der Ansicht statt auf
     * einer 403 – etwa über den Link in der Schadensmeldung. Phase und Bereich
     * bleiben. Nur hier: Bei jeder späteren Anfrage der Seite (hydrate, save)
     * gilt Filaments 403 – sonst speicherte etwa ein Herabgestufter mit noch
     * offenem Tab weiter.
     */
    public function mount(int|string $record): void
    {
        $event = $this->resolveRecord($record);
        if (!EventResource::canEdit($event) && EventResource::canView($event)) {
            $this->record = $event;
            $query = (string) request()->server('QUERY_STRING'); // Reihenfolge wie im Link
            $this->redirect(EventResource::getUrl('view', ['record' => $event]) . ($query ? '?' . $query : ''));

            return;
        }

        parent::mount($record);
    }
}
