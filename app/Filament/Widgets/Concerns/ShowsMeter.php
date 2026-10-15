<?php

namespace App\Filament\Widgets\Concerns;

use App\Filament\Pages\Meters;
use App\Models\Meter;
use App\Support\MeterStats;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * Für die Widgets der Seite Zählerstände (App\Filament\Pages\Meters): Zähler
 * und Jahr kommen von der Seite und bleiben fest; wer die Seite nicht sehen
 * darf, bekommt auch die Widgets nicht. Nach Erfassen, Ändern oder Löschen
 * eines Stands meldet die Seite „meter-readings-changed“ – dann neu rechnen.
 * Die Widgets laden sofort mit der Seite ($isLazy = false in jeder Klasse).
 */
trait ShowsMeter
{
    #[Locked]
    public ?int $meterId = null;

    #[Locked]
    public int $year = 0;

    private ?MeterStats $meterStats = null;

    public static function canView(): bool
    {
        return Meters::canAccess();
    }

    protected function authorizeMeter(): void
    {
        abort_unless(static::canView(), 403);
    }

    #[On('meter-readings-changed')]
    public function refreshMeter(): void
    {
        $this->meterStats = null;
    }

    protected function meter(): ?Meter
    {
        return $this->meterId === null ? null : Meter::query()->find($this->meterId);
    }

    protected function meterStats(): ?MeterStats
    {
        $meter = $this->meter();

        return $meter === null ? null : ($this->meterStats ??= MeterStats::for($meter));
    }
}
