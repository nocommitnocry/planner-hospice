<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Container;
use App\Helpers\Session;
use App\Routing\Request;
use App\Routing\Response;
use App\Routing\Router;

/**
 * Risolve la rotta e, se non è marcata `public`, richiede una sessione
 * utente valida. In assenza, redirige a /login con un flash di avviso.
 */
final class Authentication
{
    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response
    {
        $container = Container::instance();
        $router = $container->get(Router::class);
        $session = $container->get(Session::class);

        // Risoluzione rotta una volta sola — i middleware successivi e il
        // dispatch riusano $request->matchedRoute.
        $route = $router->match($request);

        if ($route === null) {
            // Lasciamo che il Router::dispatch produca la 404
            return $next($request);
        }

        if ($route->public) {
            return $next($request);
        }

        if (!$session->has('user')) {
            if ($request->wantsJson()) {
                return Response::json(['success' => false, 'message' => 'Non autenticato'], 401);
            }
            $session->flash('warning', 'Accesso richiesto.');
            return Response::redirect('/login');
        }

        return $next($request);
    }
}
