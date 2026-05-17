#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Crea (o reimposta) l'utente amministratore in modo interattivo.
 *
 * Uso:
 *   php bin/create-admin.php
 *
 * Richiede che il DB sia raggiungibile (variabili .env caricate).
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Helpers\Container;
use App\Models\UtenteModel;

Container::boot();

fwrite(STDOUT, "Creazione utente amministratore\n");
fwrite(STDOUT, "================================\n\n");

$username = prompt('Username [admin]: ') ?: 'admin';
$nome     = prompt('Nome [Amministratore]: ') ?: 'Amministratore';
$cognome  = prompt('Cognome [Sistema]: ') ?: 'Sistema';
$email    = prompt('Email [admin@hospice.local]: ') ?: 'admin@hospice.local';

$password = prompt_hidden("Password (min 10 caratteri): ");
if (strlen($password) < 10) {
    fwrite(STDERR, "Password troppo corta. Operazione annullata.\n");
    exit(1);
}
$confirm = prompt_hidden("Conferma password: ");
if ($confirm !== $password) {
    fwrite(STDERR, "Le password non coincidono. Operazione annullata.\n");
    exit(1);
}

$utenti = new UtenteModel();
$existing = $utenti->findByUsername($username);

$hash = password_hash($password, PASSWORD_DEFAULT);

if ($existing !== null) {
    $utenti->update((int) $existing['id'], [
        'password' => $hash,
        'nome'     => $nome,
        'cognome'  => $cognome,
        'email'    => $email,
        'ruolo'    => 'admin',
        'attivo'   => 1,
    ]);
    fwrite(STDOUT, "\nUtente '{$username}' aggiornato.\n");
} else {
    $id = $utenti->create([
        'username' => $username,
        'password' => $hash,
        'nome'     => $nome,
        'cognome'  => $cognome,
        'email'    => $email,
        'ruolo'    => 'admin',
        'attivo'   => 1,
    ]);
    fwrite(STDOUT, "\nUtente '{$username}' creato con id={$id}.\n");
}

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

function prompt_hidden(string $label): string
{
    fwrite(STDOUT, $label);
    if (function_exists('shell_exec') && DIRECTORY_SEPARATOR !== '\\') {
        shell_exec('stty -echo');
        $line = fgets(STDIN);
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    } else {
        $line = fgets(STDIN);
    }
    return $line === false ? '' : trim($line);
}
