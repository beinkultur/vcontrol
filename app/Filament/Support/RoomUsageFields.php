<?php

namespace App\Filament\Support;

use App\Enums\RoomUsage;
use App\Models\Event;
use App\Models\Room;
use App\Support\Audit;
use Filament\Forms\Components\ToggleButtons;
use Illuminate\Support\Facades\DB;

/**
 * Durchführung › Räume: jeder Raum einmal, wahlweise Backstage, „–“ (nicht
 * belegt) oder Büro. Gespeichert in event_room; inaktive Räume erscheinen nur,
 * wenn das Event sie schon belegt.
 */
final class RoomUsageFields
{
    private const NEUTRAL = 'neutral';

    /** @return list<ToggleButtons> */
    public static function all(): array
    {
        return Room::query()->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (Room $room): ToggleButtons => self::field($room))
            ->values()
            ->all();
    }

    private static function field(Room $room): ToggleButtons
    {
        return ToggleButtons::make("room_{$room->id}")
            ->label($room->name)
            ->options([
                RoomUsage::Backstage->value => RoomUsage::Backstage->getLabel(),
                self::NEUTRAL => '–',
                RoomUsage::Office->value => RoomUsage::Office->getLabel(),
            ])
            ->colors([RoomUsage::Backstage->value => 'primary', self::NEUTRAL => 'gray', RoomUsage::Office->value => 'warning'])
            ->grouped()
            ->afterStateHydrated(fn (ToggleButtons $component, ?Event $record) => $component->state(self::usage($record, $room)))
            ->visible(fn (?Event $record): bool => $room->is_active || self::usage($record, $room) !== self::NEUTRAL)
            ->dehydrated(false)
            ->saveRelationshipsUsing(function (Event $record, ?string $state) use ($room): void {
                $pivot = DB::table('event_room')->where('event_id', $record->getKey())->where('room_id', $room->getKey());
                $before = $pivot->value('usage_type');
                $after = RoomUsage::tryFrom((string) $state)?->value;
                $pivot->delete();
                if ($after !== null) {
                    DB::table('event_room')->insert(['event_id' => $record->getKey(), 'room_id' => $room->getKey(), 'usage_type' => $after]);
                }
                $record->unsetRelation('rooms');

                if ($before !== $after) {
                    Audit::record(
                        'event_room',
                        match (true) { $before === null => Audit::CREATED, $after === null => Audit::DELETED, default => Audit::UPDATED },
                        $before === null ? null : ['room_id' => $room->getKey(), 'usage_type' => $before],
                        $after === null ? null : ['room_id' => $room->getKey(), 'usage_type' => $after],
                        key: $record->getKey() . ':' . $room->getKey(),
                        label: $room->name,
                        eventId: (int) $record->getKey(),
                    );
                }
            });
    }

    private static function usage(?Event $record, Room $room): string
    {
        $type = $record?->rooms->firstWhere('id', $room->getKey())?->pivot?->usage_type;

        return RoomUsage::tryFrom((string) $type)?->value ?? self::NEUTRAL;
    }
}
