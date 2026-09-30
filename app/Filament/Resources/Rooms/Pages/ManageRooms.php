<?php

namespace App\Filament\Resources\Rooms\Pages;

use App\Filament\Resources\Rooms\RoomResource;
use App\Models\Room;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRooms extends ManageRecords
{
    protected static string $resource = RoomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Neue Räume ans Ende der Reihenfolge
            CreateAction::make()->mutateDataUsing(fn (array $data): array => $data + [
                'sort_order' => (int) Room::max('sort_order') + 10,
            ]),
        ];
    }
}
