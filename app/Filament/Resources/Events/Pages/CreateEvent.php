<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Resources\Pages\CreateRecord;

/** Neue Events bekommen laufende Nummer und VA-ID automatisch (siehe Event::booted). */
class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;
}
