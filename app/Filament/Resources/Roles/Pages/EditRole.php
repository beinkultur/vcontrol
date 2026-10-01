<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Roles\Pages\Concerns\GrantsOnlyOwnRights;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    use BoxedPage;
    use GrantsOnlyOwnRights;

    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $this->haltIfGrantingMoreThanOwnRights();
    }
}
