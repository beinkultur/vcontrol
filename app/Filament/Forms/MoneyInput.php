<?php

namespace App\Filament\Forms;

use App\Support\Money;
use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

/**
 * Betragsfeld mit Tausenderpunkt schon beim Tippen: „2.800,50“. Gespeichert
 * und geprüft wird die Zahl (App\Support\Money). Negative Beträge gehen,
 * etwa für einen Rabatt.
 */
class MoneyInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->mask(RawJs::make("\$money(\$input, ',', '.', 2)"))
            ->formatStateUsing(fn (mixed $state): ?string => Money::input($state))
            // Unlesbares bleibt stehen, damit „numeric“ es meldet
            ->mutateStateForValidationUsing(fn (mixed $state): mixed => Money::parse($state) ?? (filled($state) ? $state : null))
            ->dehydrateStateUsing(fn (mixed $state): ?float => Money::parse($state))
            ->rule('numeric')
            ->extraInputAttributes(['inputmode' => 'decimal']);
    }
}
