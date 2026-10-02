<?php

namespace App\Filament\Resources\ExternEvents\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\ExternEvents\ExternEventResource;
use App\Models\ExternEvent;
use App\Support\EventDisplay;
use App\Support\ExternSheet;
use Filament\Resources\Pages\ViewRecord;

/**
 * Ein Event aus Sicht der Externen. Nur Infolist, kein Formular: So landen keine
 * Spalten des Events (WLAN-Passwort, interne Notizen …) im Seitenzustand.
 */
class ViewExternEvent extends ViewRecord
{
    use BoxedPage;

    protected static string $resource = ExternEventResource::class;

    public function getHeading(): string
    {
        return (string) $this->getRecord()->getAttribute('title');
    }

    public function getSubheading(): ?string
    {
        /** @var ExternEvent $event */
        $event = $this->getRecord();

        return collect([
            ExternSheet::date($event),
            filled($event->promoter?->name) ? 'Veranstalter: ' . $event->promoter->name : null,
            EventDisplay::statusLabel($event->status),
        ])->filter()->implode(' · ');
    }
}
