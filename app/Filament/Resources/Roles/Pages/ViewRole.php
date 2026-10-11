<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** Rechte einer Rolle nur ansehen – für alle außer System-Admins (RolePolicy). */
class ViewRole extends ViewRecord
{
    use BoxedPage;

    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
