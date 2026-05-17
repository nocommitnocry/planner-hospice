<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Config;
use App\Helpers\Container;
use App\Helpers\Csrf;
use App\Helpers\Logger;
use App\Helpers\Session;
use App\Models\LoginAttemptModel;
use App\Models\UtenteModel;

/**
 * Servizio di autenticazione.
 *
 * Funzioni:
 * - login(): verifica credenziali, gestisce lockout per username+IP,
 *            rigenera session id, salva utente in sessione.
 * - logout(): distrugge sessione e cookie.
 * - changePassword(): verifica la password corrente, hasha la nuova,
 *                     rigenera session id.
 *
 * Il messaggio di errore al fallimento del login NON distingue username
 * inesistente da password errata (anti-enumeration).
 */
final class AuthService
{
    private UtenteModel $utenti;
    private LoginAttemptModel $attempts;
    private Session $session;
    private Csrf $csrf;

    public function __construct()
    {
        $container = Container::instance();
        $this->utenti = new UtenteModel();
        $this->attempts = new LoginAttemptModel();
        $this->session = $container->get(Session::class);
        $this->csrf = $container->get(Csrf::class);
    }

    /**
     * @return array{ok:bool, message:string, locked:bool}
     */
    public function login(string $username, string $password, string $ip): array
    {
        $username = trim($username);

        $max = (int) Config::get('app.auth.max_login_attempts', 5);
        $lockoutMinutes = (int) Config::get('app.auth.lockout_minutes', 15);

        if ($this->attempts->isLocked($username, 'username', $max, $lockoutMinutes)
            || $this->attempts->isLocked($ip, 'ip', $max * 3, $lockoutMinutes)) {
            Logger::get()->warning('Login bloccato per lockout', [
                'username' => $username,
                'ip'       => $ip,
            ]);
            return [
                'ok'      => false,
                'message' => "Troppi tentativi falliti. Riprova fra {$lockoutMinutes} minuti.",
                'locked'  => true,
            ];
        }

        $user = $this->utenti->findByUsername($username);

        $valid = $user !== null
            && (bool) ($user['attivo'] ?? false)
            && password_verify($password, (string) $user['password']);

        if (!$valid) {
            $this->attempts->register($username, 'username');
            $this->attempts->register($ip, 'ip');
            Logger::get()->info('Login fallito', ['username' => $username, 'ip' => $ip]);
            return [
                'ok'      => false,
                'message' => 'Username o password non corretti.',
                'locked'  => false,
            ];
        }

        // Login riuscito: rotazione id, reset tentativi, persist sessione
        $this->session->regenerate();
        $this->csrf->rotate();

        $this->attempts->reset($username, 'username');
        $this->attempts->reset($ip, 'ip');

        $this->utenti->aggiornaUltimoAccesso((int) $user['id']);

        // Memorizziamo solo i dati necessari, MAI la password
        $this->session->set('user', [
            'id'      => (int) $user['id'],
            'username'=> (string) $user['username'],
            'nome'    => (string) $user['nome'],
            'cognome' => (string) $user['cognome'],
            'ruolo'   => (string) $user['ruolo'],
        ]);

        // Verifica se l'hash va aggiornato (algoritmo evoluto)
        if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
            $this->utenti->update((int) $user['id'], [
                'password' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }

        Logger::get()->info('Login riuscito', [
            'user_id'  => (int) $user['id'],
            'username' => $username,
            'ip'       => $ip,
        ]);

        return ['ok' => true, 'message' => 'Accesso effettuato.', 'locked' => false];
    }

    public function logout(): void
    {
        $user = $this->session->get('user');
        if (is_array($user)) {
            Logger::get()->info('Logout', ['user_id' => $user['id'] ?? null]);
        }
        $this->session->destroy();
    }

    /**
     * @return array{ok:bool, message:string}
     */
    public function changePassword(int $userId, string $current, string $new, string $confirm): array
    {
        $minLength = (int) Config::get('app.auth.min_password_length', 10);

        if ($new !== $confirm) {
            return ['ok' => false, 'message' => 'La nuova password e la conferma non coincidono.'];
        }
        if (strlen($new) < $minLength) {
            return ['ok' => false, 'message' => "La password deve essere lunga almeno {$minLength} caratteri."];
        }
        if ($new === $current) {
            return ['ok' => false, 'message' => 'La nuova password deve essere diversa da quella attuale.'];
        }

        $user = $this->utenti->find($userId);
        if ($user === null || !password_verify($current, (string) $user['password'])) {
            return ['ok' => false, 'message' => 'La password attuale non è corretta.'];
        }

        $this->utenti->update($userId, [
            'password' => password_hash($new, PASSWORD_DEFAULT),
        ]);

        // Sessione: rotazione id e nuovo token CSRF
        $this->session->regenerate();
        $this->csrf->rotate();

        Logger::get()->info('Password cambiata', ['user_id' => $userId]);

        return ['ok' => true, 'message' => 'Password aggiornata con successo.'];
    }
}
