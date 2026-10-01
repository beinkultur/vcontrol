<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\Concerns\HasEventHeading;
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
            $this->historyAction(),
        ];
    }

    /**
     * Wer das Event nur lesen darf, landet in der Ansicht statt auf einer 403 –
     * etwa über den Link in der Schadensmeldung. Phase und Bereich bleiben.
     */
    protected function authorizeAccess(): void
    {
        $event = $this->getRecord();
        if (!EventResource::canEdit($event) && EventResource::canView($event)) {
            $query = (string) request()->server('QUERY_STRING'); // Reihenfolge wie im Link
            $this->redirect(EventResource::getUrl('view', ['record' => $event]) . ($query ? '?' . $query : ''));

            return;
        }

        parent::authorizeAccess();
    }
}
