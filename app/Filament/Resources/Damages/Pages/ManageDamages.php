<?php

namespace App\Filament\Resources\Damages\Pages;

use App\Filament\Resources\Damages\DamageResource;
use App\Filament\Support\DamageFields;
use App\Models\Damage;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageDamages extends ManageRecords
{
    protected static string $resource = DamageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Schaden melden')
                ->modalHeading('Schaden melden')
                ->modalSubmitActionLabel('Melden')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->modalWidth('3xl')
                ->createAnother(false)
                ->using(fn (array $data): Damage => DamageFields::create($data)),
        ];
    }
}
