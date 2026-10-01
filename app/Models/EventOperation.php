<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * Betriebsdaten der Durchführung: Bus-Strom (Anzahl Anschlüsse), Stromzähler
 * Anfang/Ende mit berechnetem Verbrauch, Haus-Delay. Dazu Altdaten aus AppSheet
 * (Zähler-Verweise, Backstages/Büros als Text).
 */
#[Fillable(['power_start_ref', 'power_end_ref', 'power_meter_start', 'power_meter_end', 'power_consumption', 'backstages', 'offices', 'bus_power', 'house_delay'])]
class EventOperation extends EventDetail
{
    public const MAX_BUS_POWER = 5;

    protected $table = 'event_operations';

    protected static function booted(): void
    {
        // Verbrauch ergibt sich aus den Zählerständen; ohne beide bleibt er, wie er ist.
        static::saving(function (EventOperation $operation): void {
            $consumption = $operation->computedConsumption();
            if ($consumption !== null) {
                $operation->power_consumption = (int) round($consumption);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'power_consumption' => 'integer',
            'power_meter_start' => 'decimal:2',
            'power_meter_end' => 'decimal:2',
            'bus_power' => 'integer',
            'house_delay' => 'boolean',
        ];
    }

    public function computedConsumption(): ?float
    {
        if ($this->power_meter_start === null || $this->power_meter_end === null) {
            return null;
        }

        return (float) $this->power_meter_end - (float) $this->power_meter_start;
    }
}
