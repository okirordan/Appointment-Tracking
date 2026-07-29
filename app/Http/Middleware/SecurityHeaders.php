<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers per PRD §17. The CSP permits Vite's dev server in
 * local environments and tightens automatically everywhere else.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);

        $isEvidencePreview = $request->routeIs('evidence.preview', 'mail.attachments.preview');

        $response->headers->set('X-Frame-Options', $isEvidencePreview ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (app()->environment('local')) {
            return $response;
        }

        $response->headers->set('Content-Security-Policy', implode('; ', $isEvidencePreview ? [
            "default-src 'none'",
            "style-src 'unsafe-inline'",
            "frame-ancestors 'self'",
        ] : [
            "default-src 'self'",
            "script-src 'self' 'nonce-".Vite::cspNonce()."'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]));

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
