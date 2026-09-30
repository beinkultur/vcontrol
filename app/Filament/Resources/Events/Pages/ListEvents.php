<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'offen' => Tab::make('Offen')
                ->badge(Event::query()->where('closed', false)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('closed', false)),
            'abgeschlossen' => Tab::make('Abgeschlossen')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('closed', true)),
            'alle' => Tab::make('Alle'),
        ];
    }
}
