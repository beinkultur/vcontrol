<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\Concerns\HasEventHeading;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** Derselbe Workspace wie beim Bearbeiten, für Rollen, die Events nur lesen dürfen. */
class ViewEvent extends ViewRecord
{
    use HasEventHeading;

    protected static string $resource = EventResource::class;

    /**
     * Das Formular bekommt alle Spalten des Events in den Seitenzustand – das
     * WLAN-Passwort sehen wie in der PHP-Version aber nur Bearbeiter.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (!EventResource::canEdit($this->getRecord())) {
            unset($data['wlan_password']);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
