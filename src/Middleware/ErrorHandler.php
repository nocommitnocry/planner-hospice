<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Config;
use App\Helpers\Container;
use App\Helpers\Logger;
use App\Helpers\View;
use App\Routing\Request;
use App\Routing\Response;
use Throwable;

/**
 * Cattura qualsiasi eccezione non gestita lungo la pipeline e produce una
 * risposta utente friendly (404 / 500). In debug mostra il dettaglio.
 */
final class ErrorHandler
{
    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (Throwable $e) {
            Logger::get()->error('Eccezione non gestita', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'path'      => $request->path,
                'method'    => $request->method,
            ]);

            if ($request->wantsJson()) {
                return Response::json(
                    ['success' => false, 'message' => 'Errore interno del server'],
                    500
                );
            }

            $debug = (bool) Config::get('app.debug', false);
            $view = Container::instance()->get(View::class);
            $body = $view->render('error/500.twig', [
                'message'   => $debug ? $e->getMessage() : 'Errore interno del server',
                'exception' => $debug ? $e : null,
                'debug'     => $debug,
            ]);
            return new Response($body, 500);
        }
    }
}
