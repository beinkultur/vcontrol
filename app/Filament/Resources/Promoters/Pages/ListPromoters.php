<?php

namespace App\Filament\Resources\Promoters\Pages;

use App\Filament\Resources\Promoters\PromoterResource;
use App\Models\Promoter;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPromoters extends ListRecords
{
    protected static string $resource = PromoterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'aktiv' => Tab::make('Aktiv')
                ->badge(Promoter::where('is_archived', false)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_archived', false)),
            'archiv' => Tab::make('Archiv')
                ->badge(Promoter::where('is_archived', true)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_archived', true)),
        ];
    }
}
