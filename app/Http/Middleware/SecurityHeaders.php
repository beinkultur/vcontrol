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

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
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
