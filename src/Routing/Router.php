<?php
declare(strict_types=1);

namespace App\Routing;

use App\Helpers\Container;
use App\Helpers\View;
use RuntimeException;

/**
 * Router: registrazione e dispatch delle rotte.
 *
 * Le rotte vengono registrate da config/routes.php tramite metodi fluenti
 * (get/post/put/delete). Il dispatch produce una Response.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array{0:class-string,1:string}|callable $handler
     * @param list<string>|null $roles
     */
    public function get(string $pattern, array|\Closure $handler, ?array $roles = null, bool $public = false, ?string $name = null): Route
    {
        return $this->add('GET', $pattern, $handler, $roles, csrf: false, public: $public, name: $name);
    }

    /**
     * @param array{0:class-string,1:string}|callable $handler
     * @param list<string>|null $roles
     */
    public function post(string $pattern, array|\Closure $handler, ?array $roles = null, bool $public = false, bool $csrf = true, ?string $name = null): Route
    {
        return $this->add('POST', $pattern, $handler, $roles, csrf: $csrf, public: $public, name: $name);
    }

    /**
     * @param array{0:class-string,1:string}|callable $handler
     * @param list<string>|null $roles
     */
    public function put(string $pattern, array|\Closure $handler, ?array $roles = null, ?string $name = null): Route
    {
        return $this->add('PUT', $pattern, $handler, $roles, csrf: true, public: false, name: $name);
    }

    /**
     * @param array{0:class-string,1:string}|callable $handler
     * @param list<string>|null $roles
     */
    public function delete(string $pattern, array|\Closure $handler, ?array $roles = null, ?string $name = null): Route
    {
        return $this->add('DELETE', $pattern, $handler, $roles, csrf: true, public: false, name: $name);
    }

    /**
     * @param array{0:class-string,1:string}|callable $handler
     * @param list<string>|null $roles
     */
    private function add(string $method, string $pattern, array|\Closure $handler, ?array $roles, bool $csrf, bool $public, ?string $name): Route
    {
        $route = new Route($method, $pattern, $handler, $roles, $csrf, $public, $name);
        $this->routes[] = $route;
        return $route;
    }

    public function match(Request $request): ?Route
    {
        foreach ($this->routes as $route) {
            if ($route->method !== $request->method) {
                continue;
            }
            [$regex, $names] = $route->compile();
            if (preg_match($regex, $request->path, $m)) {
                $params = [];
                foreach ($names as $n) {
                    $params[$n] = $m[$n];
                }
                $request->routeParams = $params;
                $request->matchedRoute = $route;
                return $route;
            }
        }
        return null;
    }

    public function dispatch(Request $request): Response
    {
        $route = $request->matchedRoute ?? $this->match($request);

        if ($route === null) {
            return $this->renderError(404, 'Pagina non trovata');
        }

        $handler = $route->handler;

        if ($handler instanceof \Closure) {
            $result = $handler($request);
        } else {
            [$class, $action] = $handler;
            if (!class_exists($class)) {
                throw new RuntimeException("Controller non trovato: {$class}");
            }
            $instance = $this->container->make($class);
            if (!method_exists($instance, $action)) {
                throw new RuntimeException("Azione non trovata: {$class}::{$action}");
            }
            $result = $instance->{$action}($request);
        }

        if ($result instanceof Response) {
            return $result;
        }
        if (is_string($result)) {
            return Response::html($result);
        }
        return Response::json($result);
    }

    private function renderError(int $status, string $message): Response
    {
        $view = $this->container->get(View::class);
        $template = match ($status) {
            403 => 'error/403.twig',
            404 => 'error/404.twig',
            default => 'error/500.twig',
        };
        $body = $view->render($template, ['message' => $message]);
        return new Response($body, $status);
    }
}
