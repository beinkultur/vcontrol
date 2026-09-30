<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Resources\Pages\EditRecord;

/** Events werden storniert, nicht gelöscht – daher keine Lösch-Aktion. */
class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;
}
