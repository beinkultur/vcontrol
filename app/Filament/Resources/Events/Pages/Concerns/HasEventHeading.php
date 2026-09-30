<?php

namespace App\Filament\Resources\Events\Pages\Concerns;

use App\Support\EventDisplay;

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
}
