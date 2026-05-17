<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * Validator per la gestione utenti applicativi.
 *
 * Note di sicurezza:
 * - La password viene validata in chiaro (lunghezza, presenza). L'HASH va fatto
 *   nel controller con password_hash() prima del save: il Validator NON conosce
 *   l'hash e non lo deve sapere.
 * - In edit la password è opzionale (vuota = lascia invariata). In create è
 *   obbligatoria. Il chiamante passa $passwordRequired al costruttore.
 */
final class UtenteValidator extends BaseValidator
{
    private const RUOLI = ['admin', 'caposala', 'visualizzatore'];

    /**
     * @param list<int> $settingIdValidi ID di setting esistenti. Vuoto = nessuna validazione FK.
     */
    public function __construct(
        private readonly bool $passwordRequired,
        private readonly int $minPasswordLength = 10,
        private readonly array $settingIdValidi = [],
    ) {
    }

    public function validate(array $input): array
    {
        $errors = [];
        $data = [];

        $username = trim((string) ($input['username'] ?? ''));
        if ($err = Rules::required($username, 'Username')) {
            $errors['username'][] = $err;
        } elseif ($err = Rules::maxLen($username, 50, 'Username')) {
            $errors['username'][] = $err;
        } elseif ($err = Rules::username($username, 'Username')) {
            $errors['username'][] = $err;
        } else {
            $data['username'] = $username;
        }

        $nome = trim((string) ($input['nome'] ?? ''));
        if ($err = Rules::required($nome, 'Nome')) {
            $errors['nome'][] = $err;
        } elseif ($err = Rules::maxLen($nome, 100, 'Nome')) {
            $errors['nome'][] = $err;
        } else {
            $data['nome'] = $nome;
        }

        $cognome = trim((string) ($input['cognome'] ?? ''));
        if ($err = Rules::required($cognome, 'Cognome')) {
            $errors['cognome'][] = $err;
        } elseif ($err = Rules::maxLen($cognome, 100, 'Cognome')) {
            $errors['cognome'][] = $err;
        } else {
            $data['cognome'] = $cognome;
        }

        $email = trim((string) ($input['email'] ?? ''));
        if ($email === '') {
            $data['email'] = null;
        } elseif ($err = Rules::maxLen($email, 150, 'Email')) {
            $errors['email'][] = $err;
        } elseif ($err = Rules::email($email, 'Email')) {
            $errors['email'][] = $err;
        } else {
            $data['email'] = $email;
        }

        $ruolo = (string) ($input['ruolo'] ?? '');
        if ($err = Rules::required($ruolo, 'Ruolo')) {
            $errors['ruolo'][] = $err;
        } elseif ($err = Rules::inSet($ruolo, self::RUOLI, 'Ruolo')) {
            $errors['ruolo'][] = $err;
        } else {
            $data['ruolo'] = $ruolo;
        }

        $data['attivo'] = Rules::toBool($input['attivo'] ?? false) ? 1 : 0;

        // Setting opzionale: NULL = utente globale (admin/visualizzatore senza setting di default).
        $settingRaw = $input['id_setting'] ?? '';
        if ($settingRaw === '' || $settingRaw === null) {
            $data['id_setting'] = null;
        } elseif ($err = Rules::integer($settingRaw, 'Setting')) {
            $errors['id_setting'][] = $err;
        } else {
            $set = (int) $settingRaw;
            if ($this->settingIdValidi !== [] && !in_array($set, $this->settingIdValidi, true)) {
                $errors['id_setting'][] = 'Setting non valido.';
            } else {
                $data['id_setting'] = $set;
            }
        }

        // Password: la trattiamo a parte e la mettiamo in CHIARO sul $data,
        // sotto la chiave riservata `_password_plain`. Il controller dovrà
        // chiamare password_hash() PRIMA di passarla al model.
        $password = (string) ($input['password'] ?? '');
        $confirm  = (string) ($input['password_confirm'] ?? '');

        if ($password === '' && !$this->passwordRequired) {
            // Edit con password lasciata vuota: nessun cambio password.
        } else {
            if ($err = Rules::required($password, 'Password')) {
                $errors['password'][] = $err;
            } elseif ($err = Rules::minLen($password, $this->minPasswordLength, 'Password')) {
                $errors['password'][] = $err;
            } elseif ($password !== $confirm) {
                $errors['password_confirm'][] = 'La conferma password non coincide.';
            } else {
                $data['_password_plain'] = $password;
            }
        }

        return $this->result($data, $errors);
    }
}
