<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Gestione token CSRF per la sessione corrente.
 *
 * Strategia: un singolo token per-session, rinnovato al login/logout.
 * Validazione con hash_equals (timing-safe).
 */
final class Csrf
{
    public const FIELD = '_token';
    private const SESSION_KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    public function validate(?string $candidate): bool
    {
        if (!is_string($candidate) || $candidate === '') {
            return false;
        }
        $current = (string) $this->session->get(self::SESSION_KEY, '');
        if ($current === '') {
            return false;
        }
        return hash_equals($current, $candidate);
    }

    /** Rigenera il token (chiamare al login e al cambio password). */
    public function rotate(): void
    {
        $this->session->forget(self::SESSION_KEY);
        $this->token();
    }
}
