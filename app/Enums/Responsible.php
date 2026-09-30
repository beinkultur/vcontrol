<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Wer eine Leistung stellt. */
enum Responsible: string implements HasLabel
{
    case Arena = 'arena';
    case Promoter = 'promoter';

    public function getLabel(): string
    {
        return match ($this) {
            self::Arena => 'Halle',
            self::Promoter => 'Veranstalter',
        };
    }
}
