<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Container;
use App\Helpers\Csrf as CsrfHelper;
use App\Helpers\Logger;
use App\Routing\Request;
use App\Routing\Response;

/**
 * Valida il token CSRF su tutte le richieste mutanti (POST/PUT/PATCH/DELETE)
 * a meno che la rotta sia esplicitamente marcata `csrf: false`.
 */
final class Csrf
{
    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response
    {
        if (!$request->isMutating()) {
            return $next($request);
        }

        $route = $request->matchedRoute;
        if ($route !== null && !$route->csrf) {
            return $next($request);
        }

        $csrf = Container::instance()->get(CsrfHelper::class);
        $candidate = (string) ($request->post[CsrfHelper::FIELD] ?? $request->server['HTTP_X_CSRF_TOKEN'] ?? '');

        if (!$csrf->validate($candidate)) {
            Logger::get()->warning('CSRF token non valido', [
                'path'   => $request->path,
                'method' => $request->method,
                'ip'     => $request->ip(),
            ]);
            if ($request->wantsJson()) {
                return Response::json(['success' => false, 'message' => 'Token CSRF non valido'], 419);
            }
            return new Response('Token CSRF non valido o scaduto. Ricarica la pagina e riprova.', 419);
        }

        return $next($request);
    }
}
