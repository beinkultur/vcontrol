<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Für jede Antwort: in Produktion nur über HTTPS, dazu die üblichen
 * Sicherheits-Header. Ohne Umleitung lieferte http://… die Anmeldeseite aus,
 * deren Formular die Zugangsdaten unverschlüsselt verschickt hätte.
 *
 * Keine vollständige Content-Security-Policy: Alpine und Livewire brauchen
 * Inline-Skripte. Die Basis-Regeln hier verhindern Einbetten in fremde Seiten
 * (Clickjacking), fremde <base>-Adressen und Plugins. Antworten mit eigener
 * Policy (Datei-Downloads, Bühnenplan-SVG) behalten ihre.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isSecure() && app()->isProduction()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        $response = $next($request);
        $headers = $response->headers;

        // Nur, was die Antwort nicht selbst festlegt (Daysheet: Referrer-Policy no-referrer)
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ];
        foreach ($defaults as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }
        // Nur für Seiten: an PDFs würde object-src den Viewer im Browser blockieren
        $type = (string) $headers->get('Content-Type');
        if (!$headers->has('Content-Security-Policy') && ($type === '' || str_starts_with($type, 'text/html'))) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'");
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
