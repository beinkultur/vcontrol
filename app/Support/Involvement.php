<?php

namespace App\Support;

use App\Enums\ServiceCode;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * An welchen Events ein Konto beteiligt ist – wie UserInvolvement der
 * PHP-Version. Grundlage für den Extern-Bereich:
 * - jedes Konto, das selbst im Personal eines Events steht;
 * - Personen-Konten (Mitarbeiter der Venue, Freelancer) über den verknüpften
 *   Mitarbeiter im Personal – die PHP-Version kannte das nur für Mitarbeiter der
 *   Venue;
 * - Gewerke-Konten über das verknüpfte Gewerk im Personal oder in den Gewerken
 *   (Leistungen) des Events.
 */
final class Involvement
{
    /**
     * @template TModel of Event
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, User $user): Builder
    {
        $assigned = fn (string $type, int $id): \Closure => fn (Builder $assignments): Builder => $assignments
            ->where('assignee_type', $type)
            ->where('assignee_id', $id);

        return $query->where(function (Builder $query) use ($user, $assigned): void {
            $query->whereHas('assignments', $assigned('user', (int) $user->getKey()));

            if (($employeeId = $user->linkedEmployeeId()) !== null) {
                $query->orWhereHas('assignments', $assigned('employee', $employeeId));
            }

            if (($tradeId = $user->linkedTradeId()) !== null) {
                $query->orWhereHas('assignments', $assigned('trade', $tradeId))
                    ->orWhereHas('services', fn (Builder $services): Builder => $services->where('trade_id', $tradeId)
                        ->whereIn('service', ServiceCode::activeValues()));
            }
        });
    }

    public static function involves(User $user, Event $event): bool
    {
        return self::scope(Event::query()->whereKey($event->getKey()), $user)->exists();
    }
}
