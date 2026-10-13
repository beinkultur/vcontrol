<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Wunschtermin einer Anfrage (vc_inquiry_dates), ein Tag. */
#[Fillable(['inquiry_id', 'option_date'])]
class InquiryDate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'option_date' => 'date',
        ];
    }

    /** @return BelongsTo<Inquiry, $this> */
    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }
}
