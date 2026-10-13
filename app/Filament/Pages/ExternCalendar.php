<?php

namespace App\Filament\Pages;

use App\Access\Area;
use App\Models\User;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Kalender für Externe (Freelancer, Gewerke) wie /extern/kalender der
 * PHP-Version: dieselben Ansichten und lesbaren Ebenen, nur zum Ansehen. Events
 * sind verlinkt (fett, ↗), wenn das Konto daran beteiligt ist, sonst nicht.
 */
class ExternCalendar extends Calendar
{
    protected static ?string $slug = 'extern/kalender';

    protected static ?string $title = 'Kalender (extern)';

    protected static ?string $navigationLabel = 'Kalender';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can(Area::KalenderExtern);
    }

    /** Im Menü nur für Externe – intern gibt es den Kalender. */
    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return $user instanceof User && !$user->access()->canUseApp() && static::canAccess();
    }

    protected static function isExtern(): bool
    {
        return true;
    }
}
