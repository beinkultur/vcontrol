<?php

namespace App\Filament\Resources\Accounting\Pages;

use App\Filament\Resources\Accounting\AccountingResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Grouping\Group;

/**
 * Listen wie in der PHP-Version, seit 21.10.2026 im Filter „Liste“ statt in
 * Reitern: Events mit „Endabrechnung gestellt“ verlassen die normalen Listen und
 * stehen unter „Endabrechnung“, bis sie archivreif sind (abgeschlossen und alle
 * aktiven Eingangsrechnungen da) – dann „Archiv“. Nach Monaten gruppiert,
 * solange nach Datum sortiert ist.
 */
class ListAccounting extends ListRecords
{
    protected static string $resource = AccountingResource::class;

    /** Kein Pfad über der Liste – wie bei den Events */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** Nach einer anderen Spalte sortiert: ohne Monatsgruppen, sonst sortierten diese zuerst */
    public function getTableGrouping(): ?Group
    {
        $column = $this->getTableSortColumn();
        if (filled($column) && $column !== 'starts_at') {
            return null;
        }

        return parent::getTableGrouping();
    }

    /** Monate in der Richtung der Datumssortierung; Abgeschlossen und Archiv neueste zuerst */
    public function getTableGroupingDirection(): ?string
    {
        if ($this->getTableSortColumn() === 'starts_at') {
            return $this->getTableSortDirection() === 'desc' ? 'desc' : 'asc';
        }
        if (blank($this->tableGrouping) && in_array($this->tableFilters['state']['state'] ?? 'offen', AccountingResource::DESCENDING_STATES, true)) {
            return 'desc';
        }

        return parent::getTableGroupingDirection();
    }
}
