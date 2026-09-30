<?php

namespace App\Filament\Resources\Promoters\Pages;

use App\Filament\Resources\Promoters\PromoterResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Kein Löschen: Veranstalter hängen an Events und VA-IDs. Wer nicht mehr
 * aktiv ist, wird archiviert.
 */
class EditPromoter extends EditRecord
{
    protected static string $resource = PromoterResource::class;
}
