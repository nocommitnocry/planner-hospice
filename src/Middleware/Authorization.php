<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Container;
use App\Helpers\Session;
use App\Helpers\View;
use App\Routing\Request;
use App\Routing\Response;

/**
 * Controllo dei ruoli dichiarati a livello di rotta.
 *
 * Regole:
 * - rotte pubbliche: passano sempre (gestite a monte da Authentication).
 * - $route->allowedRoles === null: qualunque utente autenticato.
 * - $route->allowedRoles === []:    qualunque utente autenticato (alias di null).
 * - $route->allowedRoles non vuoto: l'utente deve avere uno dei ruoli elencati.
 *
 * Gli admin sono sempre autorizzati indipendentemente dall'elenco.
 */
final class Authorization
{
    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response
    {
        $route = $request->matchedRoute;
        if ($route === null || $route->public) {
            return $next($request);
        }

        $container = Container::instance();
        $session = $container->get(Session::class);
        $user = $session->get('user');

        if (!is_array($user)) {
            // Authentication avrebbe già reindirizzato; per sicurezza 401.
            return Response::forbidden();
        }

        $role = (string) ($user['ruolo'] ?? '');
        $allowed = $route->allowedRoles;

        // admin = bypass
        if ($role === 'admin') {
            return $next($request);
        }

        if ($allowed === null || $allowed === []) {
            return $next($request);
        }

        if (in_array($role, $allowed, true)) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return Response::json(['success' => false, 'message' => 'Permesso negato'], 403);
        }
        $view = $container->get(View::class);
        $body = $view->render('error/403.twig', ['message' => 'Non hai i permessi per accedere a questa pagina.']);
        return new Response($body, 403);
    }
}
