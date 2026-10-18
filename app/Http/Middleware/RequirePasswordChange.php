<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\ChangePassword;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wessen Passwort ein Admin vergeben hat (users.must_change_password), der
 * landet nach der Anmeldung auf „Neues Passwort festlegen“ (ChangePassword),
 * bis er ein eigenes vergeben hat – erst danach geht es zur angefragten Seite.
 * Durch lässt sie nur diese Seite und das Abmelden. Hängt an der Anmeldung des
 * Panels (authMiddleware) und damit auch an Druckansichten und Downloads
 * (routes/web.php).
 */
class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->must_change_password || $request->routeIs(ChangePassword::ROUTE, 'filament.app.auth.logout')) {
            return $next($request);
        }

        return redirect()->guest(route(ChangePassword::ROUTE));
    }
}
