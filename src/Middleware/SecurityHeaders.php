<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Routing\Request;
use App\Routing\Response;

/**
 * Header di sicurezza standard. Una CSP molto leggera adatta a un'app
 * con asset self-hosted (niente CDN); se in futuro si introducono iframe
 * o widget esterni, rivedere frame-ancestors e default-src.
 */
final class SecurityHeaders
{
    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('Referrer-Policy', 'same-origin');
        $response->setHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // CSP: solo self per script/style; niente inline tranne quello strettamente necessario
        // (Bootstrap 5 non richiede inline JS).
        $response->setHeader(
            'Content-Security-Policy',
            "default-src 'self'; "
            . "img-src 'self' data:; "
            . "style-src 'self'; "
            . "script-src 'self'; "
            . "font-src 'self'; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "frame-ancestors 'none'"
        );

        return $response;
    }
}
