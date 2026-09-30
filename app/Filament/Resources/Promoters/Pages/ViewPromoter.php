<?php

namespace App\Filament\Resources\Promoters\Pages;

use App\Filament\Resources\Promoters\PromoterResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** Für Rollen mit „Nur lesen“: dasselbe Formular, schreibgeschützt. */
class ViewPromoter extends ViewRecord
{
    protected static string $resource = PromoterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
