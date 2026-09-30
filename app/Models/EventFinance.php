<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/** Finanzdaten eines Events – Bereich Buchhaltung. */
#[Fillable(['contract_status', 'accounting_status', 'price_list', 'rent', 'invoice_numbers', 'accounting_closed'])]
class EventFinance extends EventDetail
{
    protected $table = 'event_finances';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accounting_status' => 'array',
            'invoice_numbers' => 'array',
            'rent' => 'decimal:2',
            'accounting_closed' => 'boolean',
        ];
    }
}
