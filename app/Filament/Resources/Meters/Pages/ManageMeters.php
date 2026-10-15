<?php

namespace App\Filament\Resources\Meters\Pages;

use App\Filament\Resources\Meters\MeterResource;
use App\Models\Meter;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMeters extends ManageRecords
{
    protected static string $resource = MeterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Neue Zähler ans Ende der Reihenfolge
            CreateAction::make()->mutateDataUsing(fn (array $data): array => $data + [
                'sort_order' => (int) Meter::max('sort_order') + 10,
            ]),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Welche Zählerstände erfasst werden. Zähler „je Event“ stehen im Event unter Durchführung › Betrieb (Stand Anfang und Ende), alle anderen werden frei unter Zählerstände abgelesen. Löschen geht nur ohne Stände – sonst deaktivieren.';
    }
}
