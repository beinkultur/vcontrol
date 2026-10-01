<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Roles\Pages\Concerns\GrantsOnlyOwnRights;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    use BoxedPage;
    use GrantsOnlyOwnRights;

    protected static string $resource = RoleResource::class;

    protected function beforeCreate(): void
    {
        $this->haltIfGrantingMoreThanOwnRights();
    }
}
