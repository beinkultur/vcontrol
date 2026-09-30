<?php

namespace App\Filament\Resources\Calendars\Pages;

use App\Filament\Resources\Calendars\CalendarResource;
use App\Models\Calendar;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCalendars extends ManageRecords
{
    protected static string $resource = CalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data): array => $data + [
                'is_system' => false,
                'sort_order' => (int) Calendar::max('sort_order') + 10,
            ]),
        ];
    }
}
