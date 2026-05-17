<?php
declare(strict_types=1);

namespace App\Routing;

/**
 * Wrapper immutabile attorno alle superglobali HTTP.
 *
 * Tutto il codice applicativo accede ai dati di richiesta attraverso questa
 * classe — niente $_POST/$_GET sparsi nei controller.
 */
final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     * @param array<string,mixed> $cookies
     * @param array<string,mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
        public readonly array $cookies,
        public readonly array $files,
        /** @var array<string,string> Parametri estratti dal pattern di rotta */
        public array $routeParams = [],
        public ?Route $matchedRoute = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // Supporto method override via campo nascosto _method (utile per PUT/DELETE)
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = '/' . trim($path, '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            post: $_POST,
            server: $_SERVER,
            cookies: $_COOKIE,
            files: $_FILES,
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    public function isMutating(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public function isAjax(): bool
    {
        $with = $this->server['HTTP_X_REQUESTED_WITH'] ?? '';
        return strtolower((string) $with) === 'xmlhttprequest';
    }

    public function wantsJson(): bool
    {
        if ($this->isAjax()) {
            return true;
        }
        $accept = (string) ($this->server['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json');
    }

    public function ip(): string
    {
        // In produzione dietro reverse proxy si può configurare per leggere
        // X-Forwarded-For; per ora ci accontentiamo del REMOTE_ADDR diretto.
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return (string) ($this->server['HTTP_USER_AGENT'] ?? '');
    }
}
