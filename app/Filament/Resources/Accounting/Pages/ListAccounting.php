<?php

namespace App\Filament\Resources\Accounting\Pages;

use App\Filament\Resources\Accounting\AccountingResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listen wie in der PHP-Version. Events mit „Endabrechnung gestellt“ verlassen
 * die normalen Listen und stehen unter „Endabrechnung“, bis sie archivreif
 * sind (abgeschlossen und alle aktiven Eingangsrechnungen da) – dann „Archiv“.
 */
class ListAccounting extends ListRecords
{
    protected static string $resource = AccountingResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $ascending = fn (Builder $query): Builder => $query->orderBy('starts_at')->orderBy('title');
        $descending = fn (Builder $query): Builder => $query->orderByDesc('starts_at')->orderBy('title');

        return [
            'offen' => Tab::make('Offen')
                ->modifyQueryUsing(fn (Builder $query): Builder => $ascending($query->finalInvoiced(false)->accountingClosed(false))),
            'abgeschlossen' => Tab::make('Abgeschlossen')
                ->modifyQueryUsing(fn (Builder $query): Builder => $descending($query->finalInvoiced(false)->accountingClosed())),
            'alle' => Tab::make('Alle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $ascending($query->finalInvoiced(false))),
            'endabrechnung' => Tab::make('Endabrechnung')
                ->modifyQueryUsing(fn (Builder $query): Builder => $ascending($query->finalInvoiced()->archiveReady(false))),
            'archiv' => Tab::make('Archiv')
                ->modifyQueryUsing(fn (Builder $query): Builder => $descending($query->finalInvoiced()->archiveReady())),
        ];
    }
}
