<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Wofür ein Event einen Raum nutzt. */
enum RoomUsage: string implements HasLabel
{
    case Backstage = 'backstage';
    case Office = 'office';

    public function getLabel(): string
    {
        return match ($this) {
            self::Backstage => 'Backstage',
            self::Office => 'Büro',
        };
    }
}
