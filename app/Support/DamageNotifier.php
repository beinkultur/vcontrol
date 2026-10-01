<?php

namespace App\Support;

use App\Filament\Resources\Damages\DamageResource;
use App\Filament\Resources\Events\EventResource;
use App\Mail\DamageReported;
use App\Models\Damage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Wie DamageNotifier der PHP-Version: neue Schäden gehen per Mail an die
 * eingestellten Empfänger (VC_DAMAGE_NOTIFY), sonst an alle aktiven Benutzer
 * mit der Rolle „hausmeister“. Ein Mailfehler hält das Speichern nicht auf.
 */
final class DamageNotifier
{
    public static function notify(Damage $damage): void
    {
        $link = $damage->event_id !== null
            ? EventResource::getUrl('edit', ['record' => $damage->event_id]) . '?phase=durchfuehrung&bereich=schaeden'
            : DamageResource::getUrl();

        foreach (self::recipients() as $email) {
            try {
                Mail::to($email)->send(new DamageReported($damage, $link));
            } catch (Throwable $e) {
                Log::warning('Schadensmeldung nicht versendet', ['an' => $email, 'fehler' => $e->getMessage()]);
            }
        }
    }

    /** @return list<string> */
    public static function recipients(): array
    {
        $configured = array_values(array_filter(array_map('trim', explode(',', (string) config('venuecontrol.damage_notify')))));
        if ($configured !== []) {
            return $configured;
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('slug', 'hausmeister'))
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
