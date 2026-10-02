<?php

namespace App\Models;

/**
 * Ein Event, wie beteiligte Externe (Freelancer, Gewerke) es sehen: dieselbe
 * Tabelle, aber eine eigene Policy (ExternEventPolicy) und eine eigene Resource.
 * So bleibt die interne EventPolicy unberührt, und Filament verwechselt die
 * beiden Event-Resources nicht. Nur zum Lesen.
 */
class ExternEvent extends Event
{
    protected $table = 'events';

    /** Die Beziehungen der Elternklasse hängen an event_id, nicht an extern_event_id. */
    public function getForeignKey(): string
    {
        return 'event_id';
    }
}
