<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 1:1-Zusatzdaten eines Events, Schlüssel ist event_id. */
abstract class EventDetail extends Model
{
    protected $primaryKey = 'event_id';

    public $incrementing = false;

    public $timestamps = false;

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
