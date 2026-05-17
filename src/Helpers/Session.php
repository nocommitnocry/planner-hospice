<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Gestione sessione con cookie hardenati.
 *
 * Differenze rispetto al vecchio session_start() nudo:
 * - cookie HttpOnly, SameSite=Lax, Secure (in prod)
 * - session_regenerate_id() al login e al cambio password
 * - flash messages dedicati (consumati alla prima lettura)
 */
final class Session
{
    private bool $started = false;

    public function __construct()
    {
        $this->start();
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        $cfg = (array) Config::get('app.session', []);

        session_name((string) ($cfg['name'] ?? 'planner_hospice_session'));
        session_set_cookie_params([
            'lifetime' => (int) ($cfg['lifetime'] ?? 7200),
            'path'     => (string) ($cfg['cookie_path'] ?? '/'),
            'domain'   => '',
            'secure'   => (bool) ($cfg['secure'] ?? false),
            'httponly' => true,
            'samesite' => (string) ($cfg['samesite'] ?? 'Lax'),
        ]);
        // Salviamo le sessioni come file (default PHP) ma fuori dal tmp di sistema
        // se vogliamo isolarle: per ora restano nei path default per semplicità.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->started = false;
    }

    /**
     * Salva un messaggio flash da mostrare alla prossima richiesta.
     */
    public function flash(string $type, string $message): void
    {
        $messages = $_SESSION['_flash'] ?? [];
        $messages[] = ['type' => $type, 'message' => $message];
        $_SESSION['_flash'] = $messages;
    }

    /**
     * Estrae e svuota i messaggi flash.
     *
     * @return list<array{type:string,message:string}>
     */
    public function consumeFlash(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $messages;
    }

    /**
     * Salva input vecchio per ripopolare un form dopo redirect (POST-Redirect-GET
     * + validazione fallita). Chiamare flashInput SENZA i campi sensibili
     * (password, token).
     *
     * @param array<string,mixed> $input
     */
    public function flashInput(array $input): void
    {
        $_SESSION['_old_input'] = $input;
    }

    /** @return array<string,mixed> */
    public function consumeOldInput(): array
    {
        $input = $_SESSION['_old_input'] ?? [];
        unset($_SESSION['_old_input']);
        return is_array($input) ? $input : [];
    }

    /**
     * Salva errori di validazione per un redisplay del form.
     *
     * @param array<string,list<string>> $errors
     */
    public function flashErrors(array $errors): void
    {
        $_SESSION['_form_errors'] = $errors;
    }

    /** @return array<string,list<string>> */
    public function consumeFormErrors(): array
    {
        $errors = $_SESSION['_form_errors'] ?? [];
        unset($_SESSION['_form_errors']);
        return is_array($errors) ? $errors : [];
    }
}
