<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/** PR-Daten eines Events. */
#[Fillable(['pr_date', 'pr_status'])]
class EventPr extends EventDetail
{
    protected $table = 'event_pr';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pr_date' => 'date',
        ];
    }
}
