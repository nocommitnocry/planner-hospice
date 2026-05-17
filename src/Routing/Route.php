<?php
declare(strict_types=1);

namespace App\Routing;

/**
 * Singola rotta registrata sul Router.
 *
 * Oltre a metodo/path/handler, porta i metadati che servono ai middleware:
 * - $allowedRoles: ruoli che possono accedere ('*' = qualunque autenticato,
 *                  null = pubblica, vedi Authorization)
 * - $csrf: se true (default sui metodi mutanti) il middleware Csrf valida il token
 * - $public: se true la rotta è raggiungibile senza autenticazione
 */
final class Route
{
    /**
     * @param array{0:class-string,1:string}|callable $handler
     * @param list<string>|null $allowedRoles
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly array|\Closure $handler,
        public readonly ?array $allowedRoles = null,
        public readonly bool $csrf = true,
        public readonly bool $public = false,
        public readonly ?string $name = null,
    ) {
    }

    /**
     * Restituisce la regex compilata e l'elenco ordinato dei nomi parametro.
     *
     * @return array{0:string, 1:list<string>}
     */
    public function compile(): array
    {
        $names = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function (array $m) use (&$names): string {
                $names[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $this->pattern
        );

        return ['@^' . $regex . '$@D', $names];
    }
}
