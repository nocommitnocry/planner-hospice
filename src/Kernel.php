<?php
declare(strict_types=1);

namespace App;

use App\Helpers\Container;
use App\Helpers\Logger;
use App\Middleware\Authentication;
use App\Middleware\Authorization;
use App\Middleware\Csrf;
use App\Middleware\ErrorHandler;
use App\Middleware\SecurityHeaders;
use App\Routing\Request;
use App\Routing\Response;
use App\Routing\Router;
use Throwable;

/**
 * Kernel HTTP: orchestra bootstrap del container, pipeline middleware e dispatch.
 *
 * La pipeline è eseguita in ordine:
 *   ErrorHandler -> SecurityHeaders -> Authentication -> Csrf -> Authorization -> Route
 *
 * Ogni middleware riceve la Request e una closure $next che passa al successivo.
 */
final class Kernel
{
    private Container $container;

    public function __construct()
    {
        $this->container = Container::boot();
    }

    public function handle(): void
    {
        $request = Request::fromGlobals();

        $pipeline = $this->buildPipeline();

        try {
            $response = $pipeline($request);
        } catch (Throwable $e) {
            // Ultima rete di sicurezza: se ErrorHandler stesso fallisce.
            Logger::get()->critical('Uncaught exception in Kernel', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            $response = Response::serverError();
        }

        $response->send();
    }

    /**
     * Costruisce la pipeline middleware come closure annidate.
     *
     * @return callable(Request): Response
     */
    private function buildPipeline(): callable
    {
        /** @var Router $router */
        $router = $this->container->get(Router::class);
        $this->loadRoutes($router);

        $stack = [
            new ErrorHandler(),
            new SecurityHeaders(),
            new Authentication(),
            new Csrf(),
            new Authorization(),
        ];

        // Il "core handler" finale: esegue il routing.
        $core = fn (Request $req): Response => $router->dispatch($req);

        // Avvolgi i middleware in ordine inverso.
        return array_reduce(
            array_reverse($stack),
            fn (callable $next, $middleware): callable
                => fn (Request $req): Response => $middleware->process($req, $next),
            $core
        );
    }

    private function loadRoutes(Router $router): void
    {
        $registrar = require APP_ROOT . '/config/routes.php';
        if (is_callable($registrar)) {
            $registrar($router);
        }
    }
}
