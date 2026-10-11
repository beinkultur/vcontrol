<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageEmployees extends ManageRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Löschen geht nur, solange kein Event und kein Benutzerkonto auf den Mitarbeiter verweist – sonst archivieren.';
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'aktiv' => Tab::make('Aktiv')
                ->badge(Employee::where('is_archived', false)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_archived', false)),
            'archiv' => Tab::make('Archiv')
                ->badge(Employee::where('is_archived', true)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_archived', true)),
        ];
    }
}
