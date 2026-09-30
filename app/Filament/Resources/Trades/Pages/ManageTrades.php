<?php

namespace App\Filament\Resources\Trades\Pages;

use App\Filament\Resources\Trades\TradeResource;
use App\Models\Trade;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;

class ManageTrades extends ManageRecords
{
    protected static string $resource = TradeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modalWidth(Width::ThreeExtraLarge),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'aktiv' => Tab::make('Aktiv')
                ->badge(Trade::where('is_archived', false)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_archived', false)),
            'archiv' => Tab::make('Archiv')
                ->badge(Trade::where('is_archived', true)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_archived', true)),
        ];
    }
}
