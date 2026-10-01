<?php

namespace App\Filament\Concerns;

use Filament\Support\Enums\Width;

/**
 * Formular-, Detail- und Dashboard-Seiten mit begrenzter Breite wie in der
 * PHP-Version (1400 px, Stil in public/css/vcontrol.css). Listen behalten die
 * volle Breite des Panels.
 */
trait BoxedPage
{
    public function getMaxContentWidth(): Width|string|null
    {
        return 'vc-boxed';
    }
}
