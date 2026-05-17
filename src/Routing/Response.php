<?php
declare(strict_types=1);

namespace App\Routing;

/**
 * Risposta HTTP. Costruzione fluente; invio finale via send().
 */
final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    public function __construct(
        public string $body = '',
        public int $status = 200,
        public string $contentType = 'text/html; charset=utf-8',
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/html; charset=utf-8');
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self((string) $body, $status, 'application/json; charset=utf-8');
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $r = new self('', $status);
        $r->setHeader('Location', $url);
        return $r;
    }

    public static function notFound(string $body = ''): self
    {
        return new self($body, 404);
    }

    public static function forbidden(string $body = ''): self
    {
        return new self($body, 403);
    }

    public static function serverError(string $body = ''): self
    {
        return new self($body, 500);
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            if (!isset($this->headers['Content-Type'])) {
                header('Content-Type: ' . $this->contentType);
            }
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
