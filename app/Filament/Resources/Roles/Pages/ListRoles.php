<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getSubheading(): ?string
    {
        $user = Auth::user();

        return $user instanceof User && !$user->access()->isSystemAdmin()
            ? 'Was jede Rolle darf. Rollen und Rechte ändert der System-Admin; zugewiesen werden sie unter Benutzer.'
            : null;
    }
}
