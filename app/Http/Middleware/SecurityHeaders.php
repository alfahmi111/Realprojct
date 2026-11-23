<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response (M16, launch hardening; REQ-0444 personal data protection):
 * no framing by other sites, no MIME sniffing, no full URL in Referer to other origins, browser features limited to
 * what the app uses (camera for QR scanning, geolocation off), and HSTS when served over HTTPS.
 * A strict Content-Security-Policy is not set: Filament/Livewire rely on inline scripts; frame-ancestors covers framing.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Frame-Options', 'SAMEORIGIN', false);
        $h->set('X-Content-Type-Options', 'nosniff', false);
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $h->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(self), usb=()', false);
        if (! $h->has('Content-Security-Policy')) {
            $h->set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
        }
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', false);
        }
        return $response;
    }
}
